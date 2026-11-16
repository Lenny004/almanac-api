<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Briefing\Source\BriefingSourceInterface;
use App\Briefing\Source\SourceUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Calls every registered briefing source and collects their data.
 * A failing source never breaks the briefing: it is reported as missing.
 */
final class BriefingAggregator
{
    /**
     * @param iterable<BriefingSourceInterface> $sources every class tagged as a briefing source
     * @param LoggerInterface $logger records why a source failed
     */
    public function __construct(
        #[AutowireIterator('app.briefing_source')]
        private readonly iterable $sources,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Asks the sources for their data; a failing source is listed in "missing".
     *
     * @param \DateTimeImmutable $date the requested day
     * @param list<string>|null $onlySources names of the sources to ask, or null to ask all of them
     * @return array{date: string, sources: array<string, array<string, mixed>>, missing: list<string>}
     */
    public function aggregate(\DateTimeImmutable $date, ?array $onlySources = null): array
    {
        $sourceData = [];
        $missing = [];

        foreach ($this->sources as $source) {
            if (null !== $onlySources && !\in_array($source->getName(), $onlySources, true)) {
                continue;
            }

            try {
                $sourceData[$source->getName()] = $source->fetch($date);
            } catch (SourceUnavailableException $exception) {
                // Expected failure: upstream down, timeout or bad payload.
                $missing[] = $source->getName();
                $this->logger->warning('Briefing source unavailable.', [
                    'source' => $source->getName(),
                    'date' => $date->format('Y-m-d'),
                    'error' => $exception->getMessage(),
                ]);
            } catch (\Throwable $exception) {
                // Unexpected failure (a bug or an odd payload): still never break the briefing.
                $missing[] = $source->getName();
                $this->logger->error('Briefing source failed unexpectedly.', [
                    'source' => $source->getName(),
                    'date' => $date->format('Y-m-d'),
                    'exception' => $exception,
                ]);
            }
        }

        return [
            'date' => $date->format('Y-m-d'),
            'sources' => $sourceData,
            'missing' => $missing,
        ];
    }
}
