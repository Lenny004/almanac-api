<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Bookmark;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Database queries for bookmarks. Every method is scoped to one user.
 *
 * @extends ServiceEntityRepository<Bookmark>
 */
class BookmarkRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry gives access to Doctrine's entity managers
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bookmark::class);
    }

    /**
     * Returns one page of the user's bookmarks, newest first.
     *
     * @param User $user owner of the bookmarks
     * @param int $page page number, starting at 1
     * @param int $perPage how many bookmarks per page
     * @return list<Bookmark>
     */
    public function findPageForUser(User $user, int $page, int $perPage): array
    {
        /** @var list<Bookmark> $bookmarks */
        $bookmarks = $this->createQueryBuilder('b')
            ->where('b.user = :user')
            ->setParameter('user', $user)
            // The id breaks ties when two bookmarks share the same second.
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return $bookmarks;
    }

    /**
     * @param User $user owner of the bookmarks
     * @return int how many bookmarks the user has in total
     */
    public function countForUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    /**
     * Finds a bookmark only if it belongs to the user, so nobody can reach another user's bookmark.
     *
     * @param int $id id of the bookmark
     * @param User $user owner the bookmark must belong to
     * @return Bookmark|null the bookmark, or null when it does not exist or belongs to someone else
     */
    public function findOneForUser(int $id, User $user): ?Bookmark
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * Deletes the bookmark from the database right away.
     *
     * @param Bookmark $bookmark the bookmark to delete
     */
    public function remove(Bookmark $bookmark): void
    {
        $this->getEntityManager()->remove($bookmark);
        $this->getEntityManager()->flush();
    }
}
