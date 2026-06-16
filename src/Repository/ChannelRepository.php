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
     * Capabilities filter is applied in PHP (DQL has no JSON_CONTAINS
     * built-in; pulling the row-list and array-filtering keeps the
     * code portable across MySQL/MariaDB/Postgres).
     *
     * @return list<Channel>
     */
    public function findEnabledInbound(Workspace $workspace): array
    {
        /** @var list<Channel> $candidates */
        $candidates = $this->createQueryBuilder('c')
            ->where('c.workspace = :ws')
            ->andWhere('c.isEnabled = 1')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('ws', $workspace)
            ->getQuery()
            ->getResult();
        return array_values(array_filter(
            $candidates,
            fn (Channel $c) => \in_array('inbound', $c->getCapabilities(), true),
        ));
    }
}
