<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BookmarkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A discovery (event, picture, weather...) that a user saved from a briefing.
 *
 * The discovery key identifies the discovery itself, so the same user
 * cannot bookmark it twice while different users can.
 */
#[ORM\Entity(repositoryClass: BookmarkRepository::class)]
#[ORM\Table(name: 'bookmark')]
#[ORM\UniqueConstraint(name: 'uniq_bookmark_user_discovery', columns: ['user_id', 'discovery_key'])]
#[ORM\Index(name: 'idx_bookmark_user_created', columns: ['user_id', 'created_at'])]
class Bookmark
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param User $user owner of the bookmark
     * @param string $type kind of discovery: event, apod, year or weather
     * @param \DateTimeImmutable $date date of the briefing the discovery comes from
     * @param string $discoveryKey stable identifier of the discovery, e.g. "event:2020-11-13:1999"
     * @param string $title short text to show the bookmark
     * @param string|null $url link to the discovery, when there is one
     * @param \DateTimeImmutable $createdAt when the bookmark was saved
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 20)]
        private string $type,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $date,
        #[ORM\Column(length: 255)]
        private string $discoveryKey,
        #[ORM\Column(length: 500)]
        private string $title,
        #[ORM\Column(length: 2048, nullable: true)]
        private ?string $url,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @return int|null the database id, or null before the bookmark is saved
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return User the owner of the bookmark
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return string the kind of discovery
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return \DateTimeImmutable the date of the briefing
     */
    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    /**
     * @return string the stable identifier of the discovery
     */
    public function getDiscoveryKey(): string
    {
        return $this->discoveryKey;
    }

    /**
     * @return string the text shown for the bookmark
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return string|null the link to the discovery
     */
    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * @return \DateTimeImmutable when the bookmark was saved
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}