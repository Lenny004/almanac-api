<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bookmark\BookmarkCreator;
use App\Bookmark\CreateBookmarkRequest;
use App\Entity\Bookmark;
use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Bookmarks of the authenticated user. Every action only touches the user's own bookmarks.
 */
#[Route('/api/bookmarks', name: 'bookmark_')]
final class BookmarkController
{
    /**
     * @param BookmarkCreator $creator saves new bookmarks
     */
    public function __construct(
        private readonly BookmarkCreator $creator,
    ) {
    }

    /**
     * Creates a bookmark from the JSON body.
     *
     * @param User $user the authenticated user, injected by Symfony Security
     * @param CreateBookmarkRequest $request the JSON body, deserialized and validated by Symfony
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] CreateBookmarkRequest $request): JsonResponse
    {
        $bookmark = $this->creator->create($user, $request);

        return new JsonResponse($this->toArray($bookmark), Response::HTTP_CREATED);
    }

    /**
     * Shapes a bookmark for the JSON response, without its owner's private data.
     *
     * @param Bookmark $bookmark the bookmark to show
     * @return array<string, mixed>
     */
    private function toArray(Bookmark $bookmark): array
    {
        return [
            'id' => $bookmark->getId(),
            'type' => $bookmark->getType(),
            'date' => $bookmark->getDate()->format('Y-m-d'),
            'discoveryKey' => $bookmark->getDiscoveryKey(),
            'title' => $bookmark->getTitle(),
            'url' => $bookmark->getUrl(),
            'createdAt' => $bookmark->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
