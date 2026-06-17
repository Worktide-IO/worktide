<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\EntitySync;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<EntitySync>
 */
class EntitySyncRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntitySync::class);
    }

    /**
     * Look up the mapping for one (channel, externalId) pair —
     * used by the inbound webhook receivers and reconciliation
     * worker to find "do we know this thing already?".
     */
    public function findByChannelExternal(Channel $channel, string $externalId): ?EntitySync
    {
        return $this->findOneBy(['channel' => $channel, 'externalId' => $externalId]);
    }

    /**
     * Every external mapping for one Worktide-side entity —
     * the SPA renders this as "this task is mirrored in Jira AND
     * Redmine" badges next to the task.
     *
     * @return list<EntitySync>
     */
    public function findByEntity(string $entityType, Uuid $entityId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.entityType = :t')
            ->andWhere('s.entityId = :id')
            ->setParameter('t', $entityType)
            ->setParameter('id', $entityId)
            ->getQuery()
            ->getResult();
    }
}
