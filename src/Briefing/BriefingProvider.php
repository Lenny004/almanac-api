<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Entity\Briefing;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Returns the briefing for a date, using the Postgres cache.
 *
 * Refresh policy (all dates compared in UTC):
 * - past date, complete: served from cache, never refetched (the past does not change);
 * - past date with missing sources: nothing runs in the background. Only when someone requests
 *   that date again, and the stored copy is older than REFRESH_TTL, the missing sources (and only
 *   those) are called again to fill the gaps. Stored sources are never fetched again;
 * - today or future date: refetched when the cached copy is older than REFRESH_TTL,
 *   because the weather forecast changes during the day.
 */
final class BriefingProvider
{
    /**
     * How long a cached briefing stays fresh when it may still change: today, a future date,
     * or a past date that is missing sources. "PT1H" means "a period of time of 1 hour".
     */
    private const REFRESH_TTL = 'PT1H';

    /**
     * @param BriefingAggregator $aggregator queries every source when the cache cannot be used
     * @param ManagerRegistry $doctrine gives the entity manager, and a new one after a failed insert
     */
    public function __construct(
        private readonly BriefingAggregator $aggregator,
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    /**
     * Returns the briefing for a date, from the cache or from the sources.
     *
     * 1. Look for a cached row for that date.
     * 2. If it exists and needsRefresh() says it is still valid, return it.
     * 3. A stale past date only asks its missing sources and keeps the stored ones.
     * 4. Otherwise call every source, then insert a new row or update the existing one.
     *
     * @param \DateTimeImmutable $date the requested day (time and timezone are ignored)
     * @return Briefing the stored briefing, always saved in the database
     */
    public function get(\DateTimeImmutable $date): Briefing
    {
        $utc = new \DateTimeZone('UTC');
        $requestedDate = new \DateTimeImmutable($date->format('Y-m-d'), $utc);
        $now = new \DateTimeImmutable('now', $utc);

        // 1. Look in the cache (null = this date was never fetched).
        $briefing = $this->findStored($requestedDate);

        // 2. Cached and still valid: no upstream calls at all.
        if (null !== $briefing && !$this->needsRefresh($briefing, $requestedDate, $now)) {
            return $briefing;
        }

        // 3. Past date with missing sources: the stored sources never change, so only the missing ones are asked.
        if (null !== $briefing && $this->isPastDate($requestedDate, $now)) {
            return $this->fillMissing($briefing, $requestedDate, $now);
        }

        // 4. Not cached, or today/future and stale: ask every source.
        $briefingData = $this->aggregator->aggregate($requestedDate);

        if (null === $briefing) {
            $briefing = new Briefing($requestedDate, $briefingData['sources'], $briefingData['missing'], $now);

            return $this->insert($briefing);
        }

        // Writes the UPDATE to Postgres.
        $briefing->refresh($briefingData['sources'], $briefingData['missing'], $now);
        $this->doctrine->getManager()->flush();

        return $briefing;
    }

    /**
     * Asks only the missing sources of a stored briefing and adds what they return.
     *
     * @param Briefing $briefing the stored briefing with missing sources
     * @param \DateTimeImmutable $requestedDate the requested day at 00:00 UTC
     * @param \DateTimeImmutable $now the current moment in UTC
     * @return Briefing the same briefing, updated and saved
     */
    private function fillMissing(Briefing $briefing, \DateTimeImmutable $requestedDate, \DateTimeImmutable $now): Briefing
    {
        $retriedData = $this->aggregator->aggregate($requestedDate, $briefing->getMissing());
        $sources = array_merge($briefing->getSources(), $retriedData['sources']);

        $briefing->refresh($sources, $retriedData['missing'], $now);
        $this->doctrine->getManager()->flush();

        return $briefing;
    }

    /**
     * Saves a new briefing. When two requests for the same new date arrive together,
     * both may try to insert it: the unique index on the date stops the second one,
     * which then returns the row saved by the first request instead of an error.
     *
     * @param Briefing $briefing the new briefing, not saved yet
     * @return Briefing the saved briefing, or the one another request saved first
     * @throws UniqueConstraintViolationException when the conflicting row cannot be read back
     */
    private function insert(Briefing $briefing): Briefing
    {
        $entityManager = $this->doctrine->getManager();

        try {
            $entityManager->persist($briefing);
            $entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            // A failed flush closes the entity manager, so a new one is needed to read the other row.
            $this->doctrine->resetManager();
            $storedBriefing = $this->findStored($briefing->getDate());

            if (null === $storedBriefing) {
                throw $exception;
            }

            return $storedBriefing;
        }

        return $briefing;
    }

    /**
     * @param \DateTimeImmutable $requestedDate the day at 00:00 UTC
     * @return Briefing|null the cached briefing, or null when the date was never fetched
     */
    private function findStored(\DateTimeImmutable $requestedDate): ?Briefing
    {
        return $this->doctrine
            ->getRepository(Briefing::class)
            ->findOneBy(['date' => $requestedDate]);
    }

    /**
     * Decides whether a cached briefing must be fetched again.
     *
     * - Past date, complete: never (the past does not change).
     * - Past date with missing sources: only once REFRESH_TTL has passed,
     *   so a broken upstream is retried at most once per hour, not on every request.
     * - Today or future date: once REFRESH_TTL has passed.
     *
     * @param Briefing $briefing the cached row
     * @param \DateTimeImmutable $requestedDate the requested day at 00:00 UTC
     * @param \DateTimeImmutable $now the current moment in UTC
     * @return bool true when the sources must be queried again
     */
    private function needsRefresh(Briefing $briefing, \DateTimeImmutable $requestedDate, \DateTimeImmutable $now): bool
    {
        $freshUntil = $briefing->getFetchedAt()->add(new \DateInterval(self::REFRESH_TTL));
        $isStale = $now > $freshUntil;

        if ($this->isPastDate($requestedDate, $now)) {
            return [] !== $briefing->getMissing() && $isStale;
        }

        return $isStale;
    }

    /**
     * @param \DateTimeImmutable $requestedDate the requested day at 00:00 UTC
     * @param \DateTimeImmutable $now the current moment in UTC
     * @return bool true when the requested day is before today
     */
    private function isPastDate(\DateTimeImmutable $requestedDate, \DateTimeImmutable $now): bool
    {
        $today = new \DateTimeImmutable($now->format('Y-m-d'), $now->getTimezone());

        return $requestedDate < $today;
    }
}
