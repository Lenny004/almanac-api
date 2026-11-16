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
        return 'events';
    }

    /**
     * Fetches the events of the same day and month, up to the requested year.
     *
     * @return array<string, mixed>
     * @throws SourceUnavailableException when Wikipedia fails or returns an unexpected payload
     */
    public function fetch(\DateTimeImmutable $date): array
    {
        $url = \sprintf(self::URL, $date->format('m'), $date->format('d'));

        try {
            $eventsPayload = $this->httpClient->request('GET', $url, [
                // Wikimedia asks every client to identify itself.
                'headers' => ['User-Agent' => 'AlmanacAPI/1.0 (technical test project)'],
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ])->toArray();
        } catch (ExceptionInterface $exception) {
            throw new SourceUnavailableException('Wikipedia On This Day request failed: '.$exception->getMessage(), 0, $exception);
        }

        if (!isset($eventsPayload['events']) || !\is_array($eventsPayload['events'])) {
            throw new SourceUnavailableException('Wikipedia On This Day returned an unexpected payload.');
        }

        // "On This Day" lists events from every year; keep only those up to the requested year.
        $requestedYear = (int) $date->format('Y');

        $pastEvents = array_filter(
            $eventsPayload['events'],
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
