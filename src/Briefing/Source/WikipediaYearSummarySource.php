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
     *
     * @throws SourceUnavailableException when Wikipedia fails or returns an unexpected payload
     */
    public function fetch(\DateTimeImmutable $date): array
    {
        $url = \sprintf(self::URL, (int) $date->format('Y'));

        try {
            $data = $this->httpClient->request('GET', $url, [
                // Wikimedia asks every client to identify itself.
                'headers' => ['User-Agent' => 'AlmanacAPI/1.0 (technical test project)'],
                'timeout' => 5,
                'max_duration' => 10,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException('Wikipedia Page Summary request failed: '.$e->getMessage(), 0, $e);
        }

        if (!isset($data['title'], $data['extract']) || !\is_string($data['extract'])) {
            throw new SourceUnavailableException('Wikipedia Page Summary returned an unexpected payload.');
        }

        return [
            'title' => $data['title'],
            'summary' => $data['extract'],
            'url' => $data['content_urls']['desktop']['page'] ?? null,
            'thumbnail' => $data['thumbnail']['source'] ?? null,
        ];
    }
}