<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Historical events that happened on the same day and month (Wikipedia "On This Day").
 */
final class WikipediaEventsSource implements BriefingSourceInterface
{
    private const URL = 'https://en.wikipedia.org/api/rest_v1/feed/onthisday/events/%s/%s';
    private const MAX_EVENTS = 5;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getName(): string
    {
        return 'events';
    }

    public function fetch(\DateTimeImmutable $date): array
    {
        $url = sprintf(self::URL, $date->format('m'), $date->format('d'));

        try {
            $data = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => 'AlmanacAPI/1.0 (technical test project)'],
                'timeout' => 5,
                'max_duration' => 10,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException('Wikipedia On This Day request failed: '.$e->getMessage(), 0, $e);
        }

        if (!isset($data['events']) || !\is_array($data['events'])) {
            throw new SourceUnavailableException('Wikipedia On This Day returned an unexpected payload.');
        }

        // "On This Day" lists events from every year; keep only those up to the requested year.
        $requestedYear = (int) $date->format('Y');

        $pastEvents = array_filter(
            $data['events'],
            static function (mixed $event) use ($requestedYear): bool {
                // Skip malformed entries that have no year.
                if (!\is_array($event) || !isset($event['year'])) {
                    return false;
                }

                return (int) $event['year'] <= $requestedYear;
            },
        );

        $events = [];
        foreach (\array_slice($pastEvents, 0, self::MAX_EVENTS) as $event) {
            $events[] = [
                'year' => $event['year'] ?? null,
                'text' => $event['text'] ?? null,
                'url' => $event['pages'][0]['content_urls']['desktop']['page'] ?? null,
            ];
        }

        return ['events' => $events];
    }
}