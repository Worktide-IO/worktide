<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\EntityChangeOutboxStatus;
use App\Entity\EntityChangeOutbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntityChangeOutbox>
 */
class EntityChangeOutboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntityChangeOutbox::class);
    }

    /**
     * Next batch the worker should pick up — rows whose status is
     * pending OR partial (last attempt failed for at least one
     * mapping) AND whose `nextAttemptAt` has passed.
     *
     * @return list<EntityChangeOutbox>
     */
    public function findClaimableBatch(int $limit = 25): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.status IN (:statuses)')
            ->andWhere('o.nextAttemptAt <= :now')
            ->setParameter('statuses', [
                EntityChangeOutboxStatus::Pending,
                EntityChangeOutboxStatus::Partial,
            ])
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('o.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
