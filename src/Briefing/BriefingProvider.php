<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Entity\Briefing;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Returns the briefing for a date, using the Postgres cache.
 *
 * Refresh policy (all dates compared in UTC):
 * - past date, complete: served from cache, never refetched;
 * - past date with missing sources: refetched to fill the gaps;
 * - today or future date: refetched when the cached copy is older than REFRESH_TTL.
 */
final class BriefingProvider
{
    /**
     * How long a cached briefing for today or a future date stays fresh.
     * "PT1H" means "a period of time of 1 hour".
     */
    private const REFRESH_TTL = 'PT1H';

    /**
     * @param BriefingAggregator     $aggregator queries every source when the cache cannot be used
     * @param EntityManagerInterface $entityManager Doctrine service that reads and writes the briefing table
     */
    public function __construct(
        private readonly BriefingAggregator $aggregator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Returns the briefing for a date, from the cache or from the sources.
     *
     * 1. Look for a cached row for that date.
     * 2. If it exists and needsRefresh() says it is still valid, return it.
     * 3. Otherwise call the aggregator, then insert a new row or update the existing one.
     *
     * @param \DateTimeImmutable $date the requested day (time and timezone are ignored)
     * @return Briefing the stored briefing, always saved in the database
     */
    public function get(\DateTimeImmutable $date): Briefing
    {
        $utc = new \DateTimeZone('UTC');
        $day = new \DateTimeImmutable($date->format('Y-m-d'), $utc);
        $now = new \DateTimeImmutable('now', $utc);

        // 1. Look in the cache (null = this date was never fetched).
        $briefing = $this->entityManager->getRepository(Briefing::class)->findOneBy(['date' => $day]);

        // 2. Cached and still valid: no upstream calls at all.
        if (null !== $briefing && !$this->needsRefresh($briefing, $day, $now)) {
            return $briefing;
        }

        // 3. Not cached, or stale
        $result = $this->aggregator->aggregate($day);

        if (null === $briefing) {
            $briefing = new Briefing($day, $result['sources'], $result['missing'], $now);
            $this->entityManager->persist($briefing);
        } else {
            $briefing->refresh($result['sources'], $result['missing'], $now);
        }

        // Writes the INSERT or UPDATE to Postgres.
        $this->entityManager->flush();

        return $briefing;
    }

    /**
     * Decides whether a cached briefing must be fetched again.
     *
     * - Past date: refetch only if some source was missing.
     * - Today or future date: refetch if the copy is older than REFRESH_TTL.
     *
     * @param Briefing $briefing the cached row
     * @param \DateTimeImmutable $day the requested day at 00:00 UTC
     * @param \DateTimeImmutable $now the current moment in UTC
     * @return bool true when the sources must be queried again
     */
    private function needsRefresh(Briefing $briefing, \DateTimeImmutable $day, \DateTimeImmutable $now): bool
    {
        $today = new \DateTimeImmutable($now->format('Y-m-d'), $now->getTimezone());

        if ($day < $today) {
            return [] !== $briefing->getMissing();
        }

        $freshUntil = $briefing->getFetchedAt()->add(new \DateInterval(self::REFRESH_TTL));

        return $now > $freshUntil;
    }
}