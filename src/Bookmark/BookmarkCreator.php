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
     * Characters of the title hash kept in event keys: short, but enough to tell events apart.
     */
    private const TITLE_HASH_LENGTH = 8;

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
        $discoveryKey = $this->buildDiscoveryKey($request, $date);

        if ($this->isAlreadyBookmarked($user, $discoveryKey)) {
            throw new ConflictHttpException(self::DUPLICATE_MESSAGE);
        }

        $bookmark = new Bookmark(
            $user,
            $request->type,
            $date,
            $discoveryKey,
            trim($request->title),
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
     * @param CreateBookmarkRequest $request data sent by the client
     * @param \DateTimeImmutable $date date of the briefing
     * @return string e.g. "event:11-13:1999:3fa9c2d1", "person:11-13:7b1e04aa", "year:2020" or "apod:2020-11-13"
     */
    private function buildDiscoveryKey(CreateBookmarkRequest $request, \DateTimeImmutable $date): string
    {
        return match ($request->type) {
            // An event appears on that day every year and several can share a year,
            // so month-day, event year and a short hash of its text identify it.
            'event' => \sprintf(
                'event:%s:%d:%s',
                $date->format('m-d'),
                (int) $request->eventYear,
                $this->hashTitle($request->title),
            ),
            // A person is born on one day and month, and the birth text tells people apart.
            'person' => \sprintf(
                'person:%s:%s',
                $date->format('m-d'),
                $this->hashTitle($request->title),
            ),
            // The year summary is the same for every date of that year.
            'year' => 'year:'.$date->format('Y'),
            // APOD and weather have one discovery per date.
            'apod', 'weather' => $request->type.':'.$date->format('Y-m-d'),
            // Never reached: the Validator only accepts CreateBookmarkRequest::TYPES.
            default => throw new \LogicException(\sprintf('Unknown bookmark type "%s".', $request->type)),
        };
    }

    /**
     * @param string $title title sent by the client
     * @return string the first characters of the title's SHA-1 hash
     */
    private function hashTitle(string $title): string
    {
        $fullHash = sha1(trim($title));

        return substr($fullHash, 0, self::TITLE_HASH_LENGTH);
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
