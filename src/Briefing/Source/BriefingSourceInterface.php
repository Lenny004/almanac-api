<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A public API that contributes one section to a date briefing.
 */
#[AutoconfigureTag('app.briefing_source')]
interface BriefingSourceInterface
{
    /**
     * Unique key used in the briefing response, e.g. "apod".
     */
    public function getName(): string;

    /**
     * Fetches and normalizes the data for the given date.
     *
     * @return array<string, mixed>
     *
     * @throws SourceUnavailableException when the upstream API fails or times out
     */
    public function fetch(\DateTimeImmutable $date): array;
}