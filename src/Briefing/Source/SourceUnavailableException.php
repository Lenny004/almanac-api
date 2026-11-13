<?php

declare(strict_types=1);

namespace App\Briefing\Source;

/**
 * Thrown when an upstream API fails, times out or returns unusable data.
 */
final class SourceUnavailableException extends \RuntimeException
{
}