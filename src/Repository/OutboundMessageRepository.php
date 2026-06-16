<?php

declare(strict_types=1);

namespace App\Repository;

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
}
