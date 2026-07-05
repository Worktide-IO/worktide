<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Enum\CommentTarget;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Comment>
 */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * Recent NON-hidden comments on a set of tasks, newest first — for the portal
     * notification feed ("agency replied"). The caller passes only visible ticket
     * ids and filters out the customer's own comments.
     *
     * @param list<Uuid> $taskIds
     * @return list<Comment>
     */
    public function findRecentForTaskIds(array $taskIds, int $limit = 30): array
    {
        if ($taskIds === []) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->andWhere('c.target = :target')
            ->andWhere('c.targetId IN (:ids)')
            ->andWhere('c.isHiddenForConnectUsers = false')
            ->setParameter('target', CommentTarget::Task)
            ->setParameter('ids', array_map(static fn (Uuid $id) => $id->toBinary(), $taskIds), ArrayParameterType::BINARY)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Earliest NON-hidden agency reply per task — i.e. the first visible comment
     * NOT authored by the customer's own portal user. Drives the SLA "response"
     * leg. Returns a map of task-id (rfc4122) → first-reply DateTimeImmutable.
     *
     * @param list<Uuid> $taskIds
     * @param ?string $excludeUserId the portal user's id (their comments aren't "replies")
     * @return array<string, \DateTimeImmutable>
     */
    public function firstAgencyReplyByTask(array $taskIds, ?string $excludeUserId): array
    {
        if ($taskIds === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('c')
            ->select('c.targetId AS targetId', 'MIN(c.createdAt) AS firstAt')
            ->andWhere('c.target = :target')
            ->andWhere('c.targetId IN (:ids)')
            ->andWhere('c.isHiddenForConnectUsers = false')
            ->setParameter('target', CommentTarget::Task)
            ->setParameter('ids', array_map(static fn (Uuid $id) => $id->toBinary(), $taskIds), ArrayParameterType::BINARY)
            ->groupBy('c.targetId');

        if ($excludeUserId !== null) {
            $qb->join('c.author', 'a')->andWhere('a.id != :self')
                ->setParameter('self', Uuid::fromString($excludeUserId)->toBinary());
        }

        $out = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            $id = $row['targetId'];
            $rfc = $id instanceof Uuid ? $id->toRfc4122() : (string) $id;
            $out[$rfc] = $row['firstAt'] instanceof \DateTimeImmutable
                ? $row['firstAt']
                : new \DateTimeImmutable((string) $row['firstAt']);
        }

        return $out;
    }
}
