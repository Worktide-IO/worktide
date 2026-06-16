<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tracker;
use App\Entity\TaskStatus;
use App\Entity\WorkflowTransition;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowTransition>
 */
class WorkflowTransitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowTransition::class);
    }

    /**
     * Find every transition out of $fromStatus for the given workspace
     * + tracker. Per-tracker rows shadow tracker=null baseline rows —
     * if the per-tracker set is non-empty we return only those.
     *
     * @return list<WorkflowTransition>
     */
    public function findFromStatusForTracker(
        Workspace $workspace,
        ?Tracker $tracker,
        TaskStatus $fromStatus,
    ): array {
        $qb = $this->createQueryBuilder('t')
            ->where('t.workspace = :ws')
            ->andWhere('t.fromStatus = :from')
            ->setParameter('ws', $workspace)
            ->setParameter('from', $fromStatus);

        if ($tracker !== null) {
            $qb->andWhere('t.tracker = :tracker OR t.tracker IS NULL')
                ->setParameter('tracker', $tracker);
            /** @var list<WorkflowTransition> $rows */
            $rows = $qb->getQuery()->getResult();
            // Prefer per-tracker rules: if at least one row has a non-null
            // tracker, drop the baseline rows entirely for this query.
            $specific = array_values(array_filter($rows, fn (WorkflowTransition $r) => $r->getTracker() !== null));
            return $specific !== [] ? $specific : $rows;
        }

        $qb->andWhere('t.tracker IS NULL');
        /** @var list<WorkflowTransition> $rows */
        $rows = $qb->getQuery()->getResult();
        return $rows;
    }
}
