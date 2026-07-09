<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\NotificationType;
use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Keyset page of a recipient's notifications, newest first.
     *
     * The PK is UUIDv7 (time-ordered, binary-sortable), so ordering by `id`
     * DESC is chronological and `id < :cursor` is a stable cursor even when
     * many rows share the same occurredAt second. Pass the last item's id as
     * `$cursor` to fetch the next (older) page.
     *
     * @return list<Notification>
     */
    public function paginateForRecipient(User $recipient, ?Uuid $cursor, int $limit, bool $unreadOnly = false): array
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :recipient')
            ->setParameter('recipient', $recipient)
            ->orderBy('n.id', 'DESC')
            ->setMaxResults(max(1, $limit));

        if ($unreadOnly) {
            $qb->andWhere('n.readAt IS NULL');
        }

        if ($cursor !== null) {
            $qb->andWhere('n.id < :cursor')
                ->setParameter('cursor', $cursor->toBinary());
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Has this exact domain event already produced a notification of this type
     * for the recipient? Guards the fan-out against re-processing (mirrors the
     * `notification_dedupe` unique index without risking a flush-aborting
     * constraint violation).
     */
    public function existsFor(User $recipient, Uuid $sourceEventId, NotificationType $type): bool
    {
        return null !== $this->createQueryBuilder('n')
            ->select('1')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.sourceEventId = :eventId')
            ->andWhere('n.type = :type')
            ->setParameter('recipient', $recipient)
            ->setParameter('eventId', $sourceEventId->toBinary())
            ->setParameter('type', $type->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countUnread(User $recipient): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('recipient', $recipient)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Mark one notification read — scoped to the recipient so a user can never
     * touch another user's row (the WHERE makes cross-user calls a no-op).
     *
     * @return int rows affected (0 = not found / not owned / already read)
     */
    public function markRead(User $recipient, Uuid $id): int
    {
        return (int) $this->createQueryBuilder('n')
            ->update()
            ->set('n.readAt', ':now')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.id = :id')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('recipient', $recipient)
            ->setParameter('id', $id->toBinary())
            ->getQuery()
            ->execute();
    }

    /**
     * @return int rows affected (number of previously-unread notifications)
     */
    public function markAllRead(User $recipient): int
    {
        return (int) $this->createQueryBuilder('n')
            ->update()
            ->set('n.readAt', ':now')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('recipient', $recipient)
            ->getQuery()
            ->execute();
    }
}
