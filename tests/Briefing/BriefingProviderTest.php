<?php

declare(strict_types=1);

namespace App\Tests\Briefing;

use App\Briefing\BriefingAggregator;
use App\Briefing\BriefingProvider;
use App\Briefing\Source\ApodSource;
use App\Briefing\Source\WikipediaEventsSource;
use App\Entity\Briefing;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Checks the refresh policy of past dates against the PostgreSQL test database, with fake upstream APIs.
 */
final class BriefingProviderTest extends KernelTestCase
{
    private const PAST_DATE = '2020-11-10';

    /**
     * Every URL the fake HTTP client received, to prove which upstream APIs were called.
     *
     * @var list<string>
     */
    private array $requestedUrls = [];

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        // Every test starts from an empty table, so tests never depend on each other.
        $this->entityManager->getConnection()->executeStatement('TRUNCATE briefing RESTART IDENTITY');
    }

    public function testACompletePastDateNeverCallsTheUpstreamApisAgain(): void
    {
        $this->storeBriefing(['events' => ['events' => []], 'apod' => ['title' => 'Stored galaxy']], [], '-2 days');

        $briefing = $this->createProvider()->get(new \DateTimeImmutable(self::PAST_DATE));

        self::assertSame([], $this->requestedUrls);
        self::assertSame('Stored galaxy', $briefing->getSources()['apod']['title']);
    }

    public function testAStalePastDateOnlyAsksItsMissingSourcesAndKeepsTheStoredOnes(): void
    {
        $this->storeBriefing(['events' => ['events' => [['year' => 1989, 'text' => 'A stored event']]]], ['apod'], '-2 hours');

        $briefing = $this->createProvider()->get(new \DateTimeImmutable(self::PAST_DATE));

        // Only NASA was called: the stored events were not fetched again.
        self::assertCount(1, $this->requestedUrls);
        self::assertStringContainsString('api.nasa.gov', $this->requestedUrls[0]);
        self::assertSame([], $briefing->getMissing());
        self::assertSame('A stored event', $briefing->getSources()['events']['events'][0]['text']);
        self::assertSame('A fake galaxy', $briefing->getSources()['apod']['title']);
    }

    public function testARecentlyRetriedPastDateWaitsForTheRefreshTime(): void
    {
        $this->storeBriefing(['events' => ['events' => []]], ['apod'], '-10 minutes');

        $briefing = $this->createProvider()->get(new \DateTimeImmutable(self::PAST_DATE));

        self::assertSame([], $this->requestedUrls);
        self::assertSame(['apod'], $briefing->getMissing());
    }

    /**
     * Builds the provider with the real Doctrine setup and two real sources using a fake HTTP client.
     *
     * @return BriefingProvider the provider under test
     */
    private function createProvider(): BriefingProvider
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requestedUrls[] = $url;

            if (str_contains($url, 'api.nasa.gov')) {
                return $this->jsonResponse(['title' => 'A fake galaxy', 'url' => 'https://apod.nasa.gov/apod/image/fake.jpg']);
            }

            return $this->jsonResponse(['events' => [['year' => 1999, 'text' => 'A new fake event']]]);
        });

        $aggregator = new BriefingAggregator([
            new ApodSource($httpClient, 'test-key'),
            new WikipediaEventsSource($httpClient),
        ], new NullLogger());

        return new BriefingProvider($aggregator, static::getContainer()->get(ManagerRegistry::class));
    }

    /**
     * Saves a briefing for PAST_DATE as if it had been fetched some time ago.
     *
     * @param array<string, array<string, mixed>> $sources stored data of each source
     * @param list<string> $missing names of the sources that failed
     * @param string $fetchedAgo when it was fetched, relative to now (e.g. "-2 hours")
     */
    private function storeBriefing(array $sources, array $missing, string $fetchedAgo): void
    {
        $utc = new \DateTimeZone('UTC');
        $briefing = new Briefing(
            new \DateTimeImmutable(self::PAST_DATE, $utc),
            $sources,
            $missing,
            new \DateTimeImmutable($fetchedAgo, $utc),
        );

        $this->entityManager->persist($briefing);
        $this->entityManager->flush();
        // Forget the saved object, so the provider reads the row from the database like a real request.
        $this->entityManager->clear();
    }

    /**
     * @param array<string, mixed> $data payload to encode
     * @return MockResponse a 200 response with a JSON body
     */
    private function jsonResponse(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), [
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }
}
