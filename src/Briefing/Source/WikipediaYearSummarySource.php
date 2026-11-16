<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Summary of the requested year (Wikipedia Page Summary, e.g. the "2020" article).
 */
final class WikipediaYearSummarySource implements BriefingSourceInterface
{
    private const URL = 'https://en.wikipedia.org/api/rest_v1/page/summary/%d';

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
        return 'year';
    }

    /**
     * Fetches the summary of the Wikipedia article about the date's year.
     *
     * @return array<string, mixed>
     * @throws SourceUnavailableException when Wikipedia fails or returns an unexpected payload
     */
    public function fetch(\DateTimeImmutable $date): array
    {
        $url = \sprintf(self::URL, (int) $date->format('Y'));

        try {
            $yearPayload = $this->httpClient->request('GET', $url, [
                // Wikimedia asks every client to identify itself.
                'headers' => ['User-Agent' => 'AlmanacAPI/1.0 (technical test project)'],
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ])->toArray();
        } catch (ExceptionInterface $exception) {
            throw new SourceUnavailableException('Wikipedia Page Summary request failed: '.$exception->getMessage(), 0, $exception);
        }

        if (!isset($yearPayload['title'], $yearPayload['extract']) || !\is_string($yearPayload['extract'])) {
            throw new SourceUnavailableException('Wikipedia Page Summary returned an unexpected payload.');
        }

        return [
            'title' => $yearPayload['title'],
            'summary' => $yearPayload['extract'],
            'url' => $yearPayload['content_urls']['desktop']['page'] ?? null,
            'thumbnail' => $yearPayload['thumbnail']['source'] ?? null,
        ];
    }
}
