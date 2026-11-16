<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An API user.
 * Users have no password: they authenticate with an access token sent as
 * "Authorization: Bearer <token>". Only a SHA-256 hash of the token is stored,
 * so a leaked database does not leak usable tokens.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_user_token_hash', columns: ['token_hash'])]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param string $email unique e-mail that identifies the user
     * @param string $tokenHash SHA-256 hash (hex) of the user's access token
     * @param \DateTimeImmutable $createdAt when the user was created
     */
    public function __construct(
        #[ORM\Column(length: 180)]
        private string $email,
        #[ORM\Column(length: 64)]
        private string $tokenHash,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * Hashes a plain access token the same way it is stored in the database.
     *
     * @param string $plainToken the token the client sends in the Authorization header
     * @return string the 64-character hex SHA-256 hash
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * @return int|null the database id, or null before the user is saved
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string the user's e-mail
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @return string the SHA-256 hash of the user's access token
     */
    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    /**
     * @return \DateTimeImmutable when the user was created
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The value Symfony Security uses to identify the logged-in user.
     *
     * @return non-empty-string the user's e-mail
     */
    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    /**
     * Every user has the same basic role; the API has no admin area.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    /**
     * Required by UserInterface; there are no plain-text credentials to clear.
     */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}