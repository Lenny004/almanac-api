<?php

declare(strict_types=1);

namespace App\Briefing;

/**
 * Dates the API accepts, shared by briefings and bookmarks so both follow the same rule.
 */
final class BriefingDateRange
{
    /**
     * First date with a NASA APOD picture.
     */
    public const MIN_DATE = '1995-06-16';

    /**
     * How many days ahead the weather forecast is reliable.
     */
    public const MAX_DAYS_AHEAD = 7;

    /**
     * @return string the last accepted date (today in UTC plus the forecast limit), in Y-m-d format
     */
    public static function maxDate(): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))
            ->modify(\sprintf('+%d days', self::MAX_DAYS_AHEAD))
            ->format('Y-m-d');
    }
}
