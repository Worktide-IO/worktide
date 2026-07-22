<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InboundMuteRule;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InboundMuteRule>
 */
class InboundMuteRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboundMuteRule::class);
    }

    /**
     * Enabled rules for a workspace — the set evaluated against each inbound event.
     *
     * @return list<InboundMuteRule>
     */
    public function findEnabledForWorkspace(Workspace $workspace): array
    {
        /** @var list<InboundMuteRule> $rules */
        $rules = $this->createQueryBuilder('r')
            ->andWhere('r.workspace = :ws')->setParameter('ws', $workspace)
            ->andWhere('r.isEnabled = true')
            ->getQuery()->getResult();

        return $rules;
    }
}
