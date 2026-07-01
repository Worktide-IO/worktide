<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AIRecommendation;
use App\Entity\Enum\RecommendationKind;
use App\Entity\Enum\RecommendationStatus;
use App\Entity\Enum\RecommendationTarget;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AIRecommendation>
 */
class AIRecommendationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AIRecommendation::class);
    }

    /**
     * Still-pending recommendations of a given kind for one ticket — used to
     * supersede stale ones when a fresh triage run lands.
     *
     * @return list<AIRecommendation>
     */
    public function findPendingFor(RecommendationTarget $target, Uuid $targetId, RecommendationKind $kind): array
    {
        return $this->findBy([
            'target' => $target,
            'targetId' => $targetId,
            'kind' => $kind,
            'status' => RecommendationStatus::Pending,
        ]);
    }
}
