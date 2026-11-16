<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Turns the token from "Authorization: Bearer <token>" into a user.
 * The token is hashed the same way the create-user command stored it,
 * then looked up in the database.
 */
final class AccessTokenHandler implements AccessTokenHandlerInterface
{
    /**
     * @param EntityManagerInterface $entityManager Doctrine service that reads the user table
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Finds the user that owns the given access token.
     *
     * @param string $accessToken the plain token sent by the client
     * @return UserBadge identifies the user by e-mail for Symfony Security
     * @throws BadCredentialsException when no user has this token (Symfony answers 401)
     */
    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        // Only the hash is stored, so the sent token is hashed the same way before the lookup.
        $tokenHash = User::hashToken($accessToken);
        $user = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['tokenHash' => $tokenHash]);

        if (null === $user) {
            throw new BadCredentialsException('Invalid access token.');
        }

        return new UserBadge($user->getUserIdentifier());
    }
}
