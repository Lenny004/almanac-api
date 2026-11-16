<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Daily weather summary for a fixed location (San Salvador) from Open-Meteo.
 */
final class OpenMeteoWeatherSource implements BriefingSourceInterface
{
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';
    private const ARCHIVE_URL = 'https://archive-api.open-meteo.com/v1/archive';
    private const LATITUDE = 13.6929;
    private const LONGITUDE = -89.2182;
    private const LOCATION = 'San Salvador, SV';
    /** The archive lags a few days behind, so recent dates still use the forecast endpoint. */
    private const ARCHIVE_DELAY_DAYS = 5;

    /**
     * Seconds to wait for the first byte of the answer.
     */
    private const REQUEST_TIMEOUT_SECONDS = 5;

    /**
     * Seconds the whole request may take, download included.
     */
    private const MAX_DURATION_SECONDS = 10;

    /**
     * @param HttpClientInterface $httpClient Symfony HTTP client used to call Open-Meteo
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getName(): string
    {
        return 'weather';
    }

    /**
     * Fetches the daily weather, from the archive for old dates and from the forecast otherwise.
     *
     * @return array<string, mixed>
     * @throws SourceUnavailableException when Open-Meteo fails or returns an unexpected payload
     */
    public function fetch(\DateTimeImmutable $date): array
    {
        $formattedDate = $date->format('Y-m-d');
        $archiveLimit = new \DateTimeImmutable(\sprintf('-%d days', self::ARCHIVE_DELAY_DAYS));
        $url = $date < $archiveLimit ? self::ARCHIVE_URL : self::FORECAST_URL;

        try {
            $weatherPayload = $this->httpClient->request('GET', $url, [
                'query' => [
                    'latitude' => self::LATITUDE,
                    'longitude' => self::LONGITUDE,
                    'start_date' => $formattedDate,
                    'end_date' => $formattedDate,
                    'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum',
                    'timezone' => 'auto',
                ],
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ])->toArray();
        } catch (ExceptionInterface $exception) {
            throw new SourceUnavailableException('Open-Meteo request failed: '.$exception->getMessage(), 0, $exception);
        }

        $daily = $weatherPayload['daily'] ?? null;
        if (!\is_array($daily) || !isset($daily['time'][0])) {
            throw new SourceUnavailableException('Open-Meteo returned an unexpected payload.');
        }

        return [
            'location' => self::LOCATION,
            'temperatureMax' => $daily['temperature_2m_max'][0] ?? null,
            'temperatureMin' => $daily['temperature_2m_min'][0] ?? null,
            'precipitation' => $daily['precipitation_sum'][0] ?? null,
            'unit' => 'celsius',
        ];
    }
}
