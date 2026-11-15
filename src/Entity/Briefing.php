<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cached aggregated briefing for a single date.
 *
 * Past dates are stored once and never refetched; today and future dates
 * can be refreshed through refresh().
 */
#[ORM\Entity]
#[ORM\Table(name: 'briefing')]
#[ORM\UniqueConstraint(name: 'uniq_briefing_date', columns: ['date'])]
class Briefing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Creates a cached briefing from the aggregator result.
     *
     * @param \DateTimeImmutable                  $date      the day this briefing describes
     * @param array<string, array<string, mixed>> $sources   data returned by each available source, keyed by source name
     * @param list<string>                        $missing   names of the sources that failed
     * @param \DateTimeImmutable                  $fetchedAt when the sources were queried
     */
    public function __construct(
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $date,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $sources,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $missing,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $fetchedAt,
    ) {
    }

    /**
     * Returns the database id, or null before the entity is persisted.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Returns the day this briefing describes.
     */
    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    /**
     * Returns the data of each available source, keyed by source name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * Returns the names of the sources that failed when this briefing was fetched.
     *
     * @return list<string>
     */
    public function getMissing(): array
    {
        return $this->missing;
    }

    /**
     * Returns when the sources were last queried for this date.
     */
    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    /**
     * Replaces the cached data after a refresh (used for today/future dates only).
     *
     * @param array<string, array<string, mixed>> $sources   fresh data of each available source
     * @param list<string>                        $missing   names of the sources that failed
     * @param \DateTimeImmutable                  $fetchedAt when the sources were queried again
     */
    public function refresh(array $sources, array $missing, \DateTimeImmutable $fetchedAt): void
    {
        $this->sources = $sources;
        $this->missing = $missing;
        $this->fetchedAt = $fetchedAt;
    }
}