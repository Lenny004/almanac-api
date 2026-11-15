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
     * @param iterable<BriefingSourceInterface> $sources
     */
    public function __construct(
        #[AutowireIterator('app.briefing_source')]
        private readonly iterable $sources,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{date: string, sources: array<string, array<string, mixed>>, missing: list<string>}
     */
    public function aggregate(\DateTimeImmutable $date): array
    {
        $data = [];
        $missing = [];

        foreach ($this->sources as $source) {
            try {
                $data[$source->getName()] = $source->fetch($date);
            } catch (SourceUnavailableException $e) {
                // Expected failure: upstream down, timeout or bad payload.
                $missing[] = $source->getName();
                $this->logger->warning('Briefing source unavailable.', [
                    'source' => $source->getName(),
                    'date' => $date->format('Y-m-d'),
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                // Unexpected failure (a bug or an odd payload): still never break the briefing.
                $missing[] = $source->getName();
                $this->logger->error('Briefing source failed unexpectedly.', [
                    'source' => $source->getName(),
                    'date' => $date->format('Y-m-d'),
                    'exception' => $e,
                ]);
            }
        }

        return [
            'date' => $date->format('Y-m-d'),
            'sources' => $data,
            'missing' => $missing,
        ];
    }
}