<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Source;

use App\Briefing\Source\ApodSource;
use App\Briefing\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Checks how the APOD source talks to NASA, without calling the real API.
 */
final class ApodSourceTest extends TestCase
{
    private const API_KEY = 'secret-test-key';

    /**
     * URLs end up in logs and error messages, so the key must travel in a header.
     */
    public function testTheApiKeyIsSentInAHeaderAndNeverInTheUrl(): void
    {
        $nasaResponse = new MockResponse(json_encode([
            'title' => 'A fake galaxy',
            'url' => 'https://apod.nasa.gov/apod/image/fake.jpg',
        ], \JSON_THROW_ON_ERROR));
        $source = new ApodSource(new MockHttpClient($nasaResponse), self::API_KEY);

        $source->fetch(new \DateTimeImmutable('2020-11-10'));

        self::assertStringNotContainsString(self::API_KEY, $nasaResponse->getRequestUrl());
        $sentHeaders = $nasaResponse->getRequestOptions()['normalized_headers'];
        self::assertSame(['X-Api-Key: '.self::API_KEY], $sentHeaders['x-api-key']);
    }

    /**
     * NASA can answer 200 with its generic logo instead of the picture of the day.
     */
    public function testTheNasaLogoPlaceholderIsReportedAsUnavailable(): void
    {
        $placeholderResponse = new MockResponse(json_encode([
            'title' => 'NASA',
            'url' => 'https://api.nasa.gov/assets/img/general/nasa-logo.png',
        ], \JSON_THROW_ON_ERROR));
        $source = new ApodSource(new MockHttpClient($placeholderResponse), self::API_KEY);

        $this->expectException(SourceUnavailableException::class);

        $source->fetch(new \DateTimeImmutable('2020-11-10'));
    }
}
