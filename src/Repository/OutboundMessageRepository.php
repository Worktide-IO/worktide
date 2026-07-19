<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\Enum\OutboundMessageKind;
use App\Entity\Enum\OutboundMessageStatus;
use App\Entity\OutboundMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OutboundMessage>
 */
class OutboundMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OutboundMessage::class);
    }

    /**
     * Next batch the OutboundQueue worker should attempt. Limited so
     * a backlog doesn't starve the worker on a single iteration.
     *
     * @return list<OutboundMessage>
     */
    public function findClaimableBatch(int $limit = 25): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.status = :queued')
            ->setParameter('queued', OutboundMessageStatus::Queued)
            ->orderBy('m.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether an auto-reply was already produced for this recipient on this
     * mailbox since the given cutoff. Drives the per-sender de-bounce so a
     * back-and-forth thread doesn't get a receipt on every inbound message.
     * Counts any non-failed auto-reply (queued/sending/sent) — a withheld
     * (still-queued) one also suppresses a duplicate.
     */
    public function hasRecentAutoReply(Channel $channel, string $recipientRaw, \DateTimeImmutable $since): bool
    {
        $count = (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.channel = :channel')
            ->andWhere('m.kind = :kind')
            ->andWhere('m.recipientRaw = :recipient')
            ->andWhere('m.status != :failed')
            ->andWhere('m.createdAt >= :since')
            ->setParameter('channel', $channel)
            ->setParameter('kind', OutboundMessageKind::AutoReply)
            ->setParameter('recipient', $recipientRaw)
            ->setParameter('failed', OutboundMessageStatus::Failed)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
