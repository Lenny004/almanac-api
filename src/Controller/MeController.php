<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Returns the user that owns the access token. Useful to check a token works.
 */
final class MeController
{
    /**
     * @param User $user the authenticated user, injected by Symfony Security
     */
    #[Route('/api/me', name: 'me_show', methods: ['GET'])]
    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'email' => $user->getEmail(),
            'createdAt' => $user->getCreatedAt()->format(\DATE_ATOM),
        ]);
    }
}
