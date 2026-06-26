<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\DiscoveredExternalRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiscoveredExternalRecord>
 */
class DiscoveredExternalRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscoveredExternalRecord::class);
    }

    /**
     * The single record for an external id on a channel, if already captured —
     * lets {@see \App\Service\Inbound\DiscoveredRecordCollector} upsert instead
     * of duplicating on repeated webhooks/pulls.
     */
    public function findOneByChannelExternal(Channel $channel, string $externalId): ?DiscoveredExternalRecord
    {
        return $this->findOneBy(['channel' => $channel, 'externalId' => $externalId]);
    }
}
