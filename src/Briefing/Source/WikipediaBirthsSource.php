<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Notable people born on the same day and month (Wikipedia "On This Day" births).
 */
final class WikipediaBirthsSource implements BriefingSourceInterface
{
    private const URL = 'https://en.wikipedia.org/api/rest_v1/feed/onthisday/births/%s/%s';
    private const MAX_PEOPLE = 5;

    /**
     * Seconds to wait for the first byte of the answer.
     */
    private const REQUEST_TIMEOUT_SECONDS = 5;

    /**
     * Seconds the whole request may take, download included.
     */
    private const MAX_DURATION_SECONDS = 10;

    /**
     * @param HttpClientInterface $httpClient Symfony HTTP client used to call Wikipedia
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getName(): string
    {
        return 'births';
    }

    /**
     * Fetches the people born on the same day and month, up to the requested year.
     *
     * @return array<string, mixed>
     * @throws SourceUnavailableException when Wikipedia fails or returns an unexpected payload
     */
    public function fetch(\DateTimeImmutable $date): array
    {
        $url = \sprintf(self::URL, $date->format('m'), $date->format('d'));

        try {
            $birthsPayload = $this->httpClient->request('GET', $url, [
                // Wikimedia asks every client to identify itself.
                'headers' => ['User-Agent' => 'AlmanacAPI/1.0 (technical test project)'],
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ])->toArray();
        } catch (ExceptionInterface $exception) {
            throw new SourceUnavailableException('Wikipedia On This Day births request failed: '.$exception->getMessage(), 0, $exception);
        }

        if (!isset($birthsPayload['births']) || !\is_array($birthsPayload['births'])) {
            throw new SourceUnavailableException('Wikipedia On This Day births returned an unexpected payload.');
        }

        // The list covers every year; a briefing only shows people already born on the requested date.
        $requestedYear = (int) $date->format('Y');

        $bornBefore = array_filter(
            $birthsPayload['births'],
            static function (mixed $birth) use ($requestedYear): bool {
                // Skip malformed entries that have no year.
                if (!\is_array($birth) || !isset($birth['year'])) {
                    return false;
                }

                return (int) $birth['year'] <= $requestedYear;
            },
        );

        $people = [];
        foreach (\array_slice($bornBefore, 0, self::MAX_PEOPLE) as $birth) {
            $people[] = [
                'year' => $birth['year'],
                'text' => $birth['text'] ?? null,
                'url' => $birth['pages'][0]['content_urls']['desktop']['page'] ?? null,
            ];
        }

        return ['people' => $people];
    }
}
