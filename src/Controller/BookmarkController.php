<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bookmark\BookmarkCreator;
use App\Bookmark\CreateBookmarkRequest;
use App\Entity\Bookmark;
use App\Entity\User;
use App\Repository\BookmarkRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Bookmarks of the authenticated user. Every action only touches the user's own bookmarks.
 */
#[Route('/api/bookmarks', name: 'bookmark_')]
final class BookmarkController
{
    private const PER_PAGE = 10;

    /**
     * @param BookmarkCreator $creator saves new bookmarks
     * @param BookmarkRepository $bookmarks reads the user's bookmarks
     */
    public function __construct(
        private readonly BookmarkCreator $creator,
        private readonly BookmarkRepository $bookmarks,
    ) {
    }

    /**
     * Lists the user's bookmarks, newest first, one page at a time.
     *
     * @param User $user the authenticated user, injected by Symfony Security
     * @param int $page page number from ?page=, starting at 1; anything else is a 400
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(
        #[CurrentUser] User $user,
        #[MapQueryParameter(options: ['min_range' => 1], validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] int $page = 1,
    ): JsonResponse {
        $total = $this->bookmarks->countForUser($user);
        $items = $this->bookmarks->findPageForUser($user, $page, self::PER_PAGE);

        return new JsonResponse([
            'items' => array_map($this->toArray(...), $items),
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => (int) ceil($total / self::PER_PAGE),
        ]);
    }

    /**
     * Creates a bookmark from the JSON body.
     *
     * @param User $user the authenticated user, injected by Symfony Security
     * @param CreateBookmarkRequest $request the JSON body, deserialized and validated by Symfony; other formats get a 415
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload(acceptFormat: 'json')] CreateBookmarkRequest $request): JsonResponse
    {
        $bookmark = $this->creator->create($user, $request);

        // REST convention: a 201 tells the client where the new resource lives.
        return new JsonResponse($this->toArray($bookmark), Response::HTTP_CREATED, [
            'Location' => '/api/bookmarks/'.$bookmark->getId(),
        ]);
    }

    /**
     * Shows one of the user's bookmarks; it is the URL returned in the Location header.
     *
     * @param User $user the authenticated user, injected by Symfony Security
     * @param int $id id of the bookmark from the URL
     * @throws NotFoundHttpException when the user has no bookmark with this id
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $bookmark = $this->findOwnBookmark($user, $id);

        return new JsonResponse($this->toArray($bookmark));
    }

    /**
     * Deletes one of the user's bookmarks.
     *
     * @param User $user the authenticated user, injected by Symfony Security
     * @param int $id id of the bookmark from the URL
     * @throws NotFoundHttpException when the user has no bookmark with this id
     */
    #[Route('/{id}', name: 'delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(#[CurrentUser] User $user, int $id): Response
    {
        $bookmark = $this->findOwnBookmark($user, $id);
        $this->bookmarks->remove($bookmark);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Another user's bookmark answers 404, like a missing one, so ids of other users are never revealed.
     *
     * @param User $user the authenticated user
     * @param int $id id of the bookmark
     * @return Bookmark the user's bookmark
     * @throws NotFoundHttpException when the user has no bookmark with this id
     */
    private function findOwnBookmark(User $user, int $id): Bookmark
    {
        $bookmark = $this->bookmarks->findOneForUser($id, $user);

        if (null === $bookmark) {
            throw new NotFoundHttpException('Bookmark not found.');
        }

        return $bookmark;
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
