<?php

declare(strict_types=1);

namespace App\Bookmark;

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
        #[Assert\NotBlank]
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
}
