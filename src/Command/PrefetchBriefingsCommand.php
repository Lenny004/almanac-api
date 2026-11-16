<?php

declare(strict_types=1);

namespace App\Command;

use App\Briefing\BriefingDateRange;
use App\Briefing\BriefingProvider;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills the briefing cache for a range of dates, so the API answers them without calling upstream APIs.
 */
#[AsCommand(name: 'almanac:prefetch', description: 'Fetch and store the briefings of every date in a range')]
final class PrefetchBriefingsCommand
{
    /**
     * @param BriefingProvider $provider returns cached briefings or fetches and stores new ones
     */
    public function __construct(
        private readonly BriefingProvider $provider,
    ) {
    }

    /**
     * @param SymfonyStyle $io console input and output helpers
     * @param string $from first date of the range, in Y-m-d format
     * @param string $to last date of the range, included, in Y-m-d format
     * @return int Command::SUCCESS, Command::FAILURE when a day failed, or Command::INVALID when the dates are wrong
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'First date (YYYY-MM-DD)')] string $from,
        #[Argument(description: 'Last date, included (YYYY-MM-DD)')] string $to,
    ): int {
        $error = $this->findRangeError($from, $to);
        if (null !== $error) {
            $io->error($error);

            return Command::INVALID;
        }

        $utc = new \DateTimeZone('UTC');
        // The end date is moved one day forward because DatePeriod excludes its end.
        $days = new \DatePeriod(
            new \DateTimeImmutable($from, $utc),
            new \DateInterval('P1D'),
            (new \DateTimeImmutable($to, $utc))->modify('+1 day'),
        );

        $total = iterator_count($days);
        $position = 0;
        $complete = 0;
        $partial = [];
        $failed = [];

        foreach ($days as $day) {
            ++$position;
            $date = $day->format('Y-m-d');
            $prefix = \sprintf('[%d/%d] %s', $position, $total, $date);

            // One broken day must not stop the whole range, so it is reported and skipped.
            try {
                $missing = $this->provider->get($day)->getMissing();
            } catch (\Throwable $exception) {
                $failed[] = $date;
                $io->writeln(\sprintf('%s failed: %s', $prefix, $exception->getMessage()));

                continue;
            }

            if ([] === $missing) {
                ++$complete;
                $io->writeln($prefix.' complete');

                continue;
            }

            $partial[] = $date;
            $io->writeln(\sprintf('%s missing: %s', $prefix, implode(', ', $missing)));
        }

        $this->printSummary($io, $total, $complete, $partial, $failed);

        // Missing sources still store a usable briefing, so only days that failed completely make the command fail.
        return [] === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Prints the final counts: success when every day is complete, warning for missing sources, error for failures.
     *
     * @param SymfonyStyle $io console input and output helpers
     * @param int $total number of days in the range
     * @param int $complete number of days with every source
     * @param list<string> $partial dates stored with at least one missing source
     * @param list<string> $failed dates that could not be fetched or stored at all
     */
    private function printSummary(SymfonyStyle $io, int $total, int $complete, array $partial, array $failed): void
    {
        $summary = \sprintf(
            'Processed %d day(s): %d complete, %d with missing sources, %d failed.',
            $total,
            $complete,
            \count($partial),
            \count($failed),
        );

        if ([] !== $failed) {
            $io->error([$summary, 'Failed dates: '.implode(', ', $failed)]);

            return;
        }

        if ([] !== $partial) {
            $io->warning([$summary, 'Dates with missing sources: '.implode(', ', $partial)]);

            return;
        }

        $io->success($summary);
    }

    /**
     * @param string $from first date typed by the user
     * @param string $to last date typed by the user
     * @return string|null a message explaining what is wrong, or null when the range is valid
     */
    private function findRangeError(string $from, string $to): ?string
    {
        if (!$this->isRealDate($from) || !$this->isRealDate($to)) {
            return 'Both dates must be real calendar dates in YYYY-MM-DD format.';
        }

        $maxDate = BriefingDateRange::maxDate();

        // Y-m-d strings sort like dates, so a plain string comparison is enough.
        if ($from < BriefingDateRange::MIN_DATE || $to > $maxDate) {
            return \sprintf('Dates must be between %s and %s.', BriefingDateRange::MIN_DATE, $maxDate);
        }

        if ($from > $to) {
            return 'The first date must not be after the last date.';
        }

        return null;
    }

    /**
     * @param string $date text to check
     * @return bool true when the text is a real calendar date in Y-m-d format
     */
    private function isRealDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return false !== $parsed && $parsed->format('Y-m-d') === $date;
    }
}
