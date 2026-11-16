<?php

declare(strict_types=1);

namespace App\Bookmark;

use App\Entity\Bookmark;
use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Saves bookmarks, making sure a user never bookmarks the same discovery twice.
 */
final class BookmarkCreator
{
    private const DUPLICATE_MESSAGE = 'This discovery is already bookmarked.';

    /**
     * @param EntityManagerInterface $entityManager Doctrine entry point to read and save entities
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Saves a new bookmark for the user.
     *
     * @param User $user owner of the bookmark
     * @param CreateBookmarkRequest $request data sent by the client, already validated
     * @return Bookmark the saved bookmark
     * @throws ConflictHttpException when the user already bookmarked this discovery
     */
    public function create(User $user, CreateBookmarkRequest $request): Bookmark
    {
        $date = new \DateTimeImmutable($request->date, new \DateTimeZone('UTC'));
        $discoveryKey = $this->buildDiscoveryKey($request->type, $date, $request->eventYear);

        if ($this->isAlreadyBookmarked($user, $discoveryKey)) {
            throw new ConflictHttpException(self::DUPLICATE_MESSAGE);
        }

        $bookmark = new Bookmark(
            $user,
            $request->type,
            $date,
            $discoveryKey,
            $request->title,
            $request->url,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        try {
            $this->entityManager->persist($bookmark);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Two identical requests at the same moment: the unique index stops the second one.
            throw new ConflictHttpException(self::DUPLICATE_MESSAGE);
        }

        return $bookmark;
    }

    /**
     * Builds the stable identifier of a discovery.
     *
     * @param string $type kind of discovery
     * @param \DateTimeImmutable $date date of the briefing
     * @param int|null $eventYear year of the event, only for events
     * @return string e.g. "event:11-13:1999", "year:2020" or "apod:2020-11-13"
     */
    private function buildDiscoveryKey(string $type, \DateTimeImmutable $date, ?int $eventYear): string
    {
        return match ($type) {
            // The same event appears on that day every year, so month-day plus event year identify it.
            'event' => \sprintf('event:%s:%d', $date->format('m-d'), (int) $eventYear),
            // The year summary is the same for every date of that year.
            'year' => 'year:'.$date->format('Y'),
            // APOD and weather have one discovery per date.
            default => $type.':'.$date->format('Y-m-d'),
        };
    }

    /**
     * @param User $user owner to look for
     * @param string $discoveryKey discovery to look for
     * @return bool true when the user already saved this discovery
     */
    private function isAlreadyBookmarked(User $user, string $discoveryKey): bool
    {
        $existing = $this->entityManager->getRepository(Bookmark::class)->findOneBy([
            'user' => $user,
            'discoveryKey' => $discoveryKey,
        ]);

        return null !== $existing;
    }
}
