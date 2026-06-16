<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Channel>
 */
class ChannelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Channel::class);
    }

    /**
     * Every enabled inbound channel in the workspace — what the
     * pull scheduler iterates over.
     *
     * @return list<Channel>
     */
    public function findEnabledInbound(Workspace $workspace): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.workspace = :ws')
            ->andWhere('c.isEnabled = 1')
            ->andWhere('c.deletedAt IS NULL')
            ->andWhere('JSON_CONTAINS(c.capabilities, :inbound) = 1')
            ->setParameter('ws', $workspace)
            ->setParameter('inbound', '"inbound"')
            ->getQuery()
            ->getResult();
    }
}
