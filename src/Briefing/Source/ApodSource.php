<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * NASA Astronomy Picture of the Day.
 */
final class ApodSource implements BriefingSourceInterface
{
    private const URL = 'https://api.nasa.gov/planetary/apod';

    /**
     * Seconds to wait for the first byte of the answer.
     */
    private const REQUEST_TIMEOUT_SECONDS = 5;

    /**
     * Seconds the whole request may take, download included.
     */
    private const MAX_DURATION_SECONDS = 10;

    /**
     * Asset NASA serves instead of the real picture when APOD is degraded.
     */
    private const PLACEHOLDER_MARKER = 'nasa-logo';

    /**
     * @param HttpClientInterface $httpClient Symfony HTTP client used to call NASA
     * @param string $apiKey NASA API key, read from the NASA_API_KEY environment variable
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'NASA_API_KEY')]
        private readonly string $apiKey,
    ) {
    }

    public function getName(): string
    {
        return 'apod';
    }

    /**
     * Fetches the picture of the day for the date.
     *
     * @return array<string, mixed>
     * @throws SourceUnavailableException when NASA fails, returns an unexpected payload or a placeholder
     */
    public function fetch(\DateTimeImmutable $date): array
    {
        try {
            $apodPayload = $this->httpClient->request('GET', self::URL, [
                // The key goes in a header, not in the URL, because the HTTP client
                // writes URLs to the logs and to its error messages.
                'headers' => ['X-Api-Key' => $this->apiKey],
                'query' => ['date' => $date->format('Y-m-d')],
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ])->toArray();
        } catch (ExceptionInterface $exception) {
            throw new SourceUnavailableException('NASA APOD request failed: '.$exception->getMessage(), 0, $exception);
        }

        if (!isset($apodPayload['title'], $apodPayload['url'])) {
            throw new SourceUnavailableException('NASA APOD returned an unexpected payload.');
        }

        // A 200 with the generic NASA logo is not a real picture: treat it as unavailable
        // so it is reported as missing and refetched later, never cached as valid data.
        if (str_contains((string) $apodPayload['url'], self::PLACEHOLDER_MARKER)) {
            throw new SourceUnavailableException('NASA APOD returned a placeholder instead of the picture of the day.');
        }

        return [
            'title' => $apodPayload['title'],
            'explanation' => $apodPayload['explanation'] ?? null,
            'mediaType' => $apodPayload['media_type'] ?? null,
            'url' => $apodPayload['url'],
            'hdUrl' => $apodPayload['hdurl'] ?? null,
            'copyright' => $this->cleanCopyright($apodPayload['copyright'] ?? null),
        ];
    }

    /**
     * NASA sometimes sends the copyright with line breaks around it.
     *
     * @param mixed $copyright value from the payload, when there is one
     * @return string|null the trimmed text, or null when NASA sent none
     */
    private function cleanCopyright(mixed $copyright): ?string
    {
        if (null === $copyright) {
            return null;
        }

        return trim((string) $copyright);
    }
}
