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

    public function fetch(\DateTimeImmutable $date): array
    {
        try {
            $data = $this->httpClient->request('GET', self::URL, [
                'query' => [
                    'api_key' => $this->apiKey,
                    'date' => $date->format('Y-m-d'),
                ],
                'timeout' => 5,
                'max_duration' => 10,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException('NASA APOD request failed: '.$e->getMessage(), 0, $e);
        }

        if (!isset($data['title'], $data['url'])) {
            throw new SourceUnavailableException('NASA APOD returned an unexpected payload.');
        }

        return [
            'title' => $data['title'] ?? null,
            'explanation' => $data['explanation'] ?? null,
            'mediaType' => $data['media_type'] ?? null,
            'url' => $data['url'] ?? null,
            'hdUrl' => $data['hdurl'] ?? null,
            'copyright' => isset($data['copyright']) ? trim((string) $data['copyright']) : null,
        ];
    }
}