<?php

declare(strict_types=1);

namespace App\Tests\Briefing;

use App\Briefing\BriefingAggregator;
use App\Briefing\Source\ApodSource;
use App\Briefing\Source\OpenMeteoWeatherSource;
use App\Briefing\Source\WikipediaEventsSource;
use App\Briefing\Source\WikipediaYearSummarySource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Checks the aggregator with the real sources but fake upstream APIs, so no test touches the internet.
 */
final class BriefingAggregatorTest extends TestCase
{
    private const DATE = '2020-11-10';

    public function testItCollectsEverySourceWhenAllUpstreamsAnswer(): void
    {
        $briefing = $this->aggregate();

        self::assertSame(self::DATE, $briefing['date']);
        self::assertSame([], $briefing['missing']);
        self::assertSame(['apod', 'events', 'weather', 'year'], array_keys($briefing['sources']));
        self::assertSame('A fake galaxy', $briefing['sources']['apod']['title']);
        self::assertSame(31.5, $briefing['sources']['weather']['temperatureMax']);
        self::assertCount(1, $briefing['sources']['events']['events']);
        self::assertSame('2020', $briefing['sources']['year']['title']);
    }

    public function testAFailingSourceIsReportedAsMissingWithoutBreakingTheOthers(): void
    {
        $briefing = $this->aggregate(['api.nasa.gov' => new MockResponse('Server error', ['http_code' => 500])]);

        self::assertSame(['apod'], $briefing['missing']);
        self::assertArrayNotHasKey('apod', $briefing['sources']);
        self::assertSame(['events', 'weather', 'year'], array_keys($briefing['sources']));
    }

    public function testANetworkErrorAndABadPayloadAreBothReportedAsMissing(): void
    {
        $briefing = $this->aggregate([
            'open-meteo.com' => new MockResponse('', ['error' => 'Connection timed out']),
            'page/summary' => new MockResponse('{"unexpected": true}'),
        ]);

        self::assertSame(['weather', 'year'], $briefing['missing']);
        self::assertSame(['apod', 'events'], array_keys($briefing['sources']));
    }

    /**
     * Builds the aggregator with every source sharing one fake HTTP client and runs it for DATE.
     *
     * @param array<string, MockResponse> $overrides responses that replace the happy path, keyed by a URL fragment
     * @return array{date: string, sources: array<string, array<string, mixed>>, missing: list<string>}
     */
    private function aggregate(array $overrides = []): array
    {
        $responses = array_merge($this->happyResponses(), $overrides);

        // The callback picks the fake response whose URL fragment appears in the requested URL.
        $client = new MockHttpClient(static function (string $method, string $url) use ($responses): MockResponse {
            foreach ($responses as $fragment => $response) {
                if (str_contains($url, $fragment)) {
                    return $response;
                }
            }

            return new MockResponse('Unexpected URL: '.$url, ['http_code' => 404]);
        });

        $aggregator = new BriefingAggregator([
            new ApodSource($client, 'test-key'),
            new WikipediaEventsSource($client),
            new OpenMeteoWeatherSource($client),
            new WikipediaYearSummarySource($client),
        ], new NullLogger());

        return $aggregator->aggregate(new \DateTimeImmutable(self::DATE));
    }

    /**
     * @return array<string, MockResponse> a valid fake answer for each upstream API, keyed by a URL fragment
     */
    private function happyResponses(): array
    {
        return [
            'api.nasa.gov' => $this->json([
                'title' => 'A fake galaxy',
                'url' => 'https://apod.nasa.gov/apod/image/fake.jpg',
                'media_type' => 'image',
            ]),
            'onthisday' => $this->json([
                'events' => [['year' => 1989, 'text' => 'A fake event']],
            ]),
            'open-meteo.com' => $this->json([
                'daily' => [
                    'time' => [self::DATE],
                    'temperature_2m_max' => [31.5],
                    'temperature_2m_min' => [21.0],
                    'precipitation_sum' => [0.0],
                ],
            ]),
            'page/summary' => $this->json(['title' => '2020', 'extract' => 'A fake year summary.']),
        ];
    }

    /**
     * @param array<string, mixed> $data payload to encode
     * @return MockResponse a 200 response with a JSON body
     */
    private function json(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), [
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }
}