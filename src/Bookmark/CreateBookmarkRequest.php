<?php

declare(strict_types=1);

namespace App\Bookmark;

use App\Briefing\BriefingDateRange;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * JSON body of POST /api/bookmarks, filled by the Serializer and checked by the Validator.
 */
final class CreateBookmarkRequest
{
    public const TYPES = ['event', 'apod', 'year', 'weather'];

    /**
     * Default values let the Validator report missing fields instead of the Serializer failing.
     *
     * @param string $type kind of discovery: event, apod, year or weather
     * @param string $date date of the briefing, in Y-m-d format
     * @param string $title short text to show the bookmark
     * @param string|null $url link to the discovery, when there is one
     * @param int|null $eventYear year of the event, required when the type is "event"
     */
    public function __construct(
        // Sequentially stops at the first failing rule, so an empty type reports one error, not two.
        #[Assert\Sequentially([new Assert\NotBlank(), new Assert\Choice(choices: self::TYPES)])]
        public readonly string $type = '',
        #[Assert\NotBlank]
        #[Assert\Date]
        public readonly string $date = '',
        // The trim normalizer rejects titles made only of spaces.
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 500)]
        public readonly string $title = '',
        #[Assert\Url(requireTld: true)]
        #[Assert\Length(max: 2048)]
        public readonly ?string $url = null,
        public readonly ?int $eventYear = null,
    ) {
    }

    /**
     * Events need their year to tell apart the many events of the same day.
     *
     * @param ExecutionContextInterface $context where the violation is added
     */
    #[Assert\Callback]
    public function validateEventYear(ExecutionContextInterface $context): void
    {
        if ('event' === $this->type && null === $this->eventYear) {
            $context->buildViolation('The event year is required for events.')
                ->atPath('eventYear')
                ->addViolation();
        }
    }

    /**
     * Bookmarks accept the same dates as briefings.
     *
     * @param ExecutionContextInterface $context where the violation is added
     */
    #[Assert\Callback]
    public function validateDateRange(ExecutionContextInterface $context): void
    {
        // An invalid date is already reported by Assert\Date.
        if (!$this->isRealDate($this->date)) {
            return;
        }

        $maxDate = BriefingDateRange::maxDate();

        // Y-m-d strings sort like dates, so a plain string comparison is enough.
        if ($this->date < BriefingDateRange::MIN_DATE || $this->date > $maxDate) {
            $context->buildViolation(\sprintf('The date must be between %s and %s.', BriefingDateRange::MIN_DATE, $maxDate))
                ->atPath('date')
                ->addViolation();
        }
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
