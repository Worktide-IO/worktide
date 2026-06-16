<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\InboundEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InboundEvent>
 */
class InboundEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboundEvent::class);
    }

    /**
     * Idempotency check — does this channel already have an event
     * with the given externalId? Used by adapters before persist.
     */
    public function findByExternalId(Channel $channel, string $externalId): ?InboundEvent
    {
        return $this->findOneBy(['channel' => $channel, 'externalId' => $externalId]);
    }
}
