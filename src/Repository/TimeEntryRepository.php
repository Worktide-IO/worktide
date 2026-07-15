<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\TimeEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<TimeEntry>
 */
class TimeEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimeEntry::class);
    }

    /** Total tracked minutes logged against one task (0 when none). */
    public function sumMinutesForTask(Uuid $taskId): int
    {
        return (int) $this->createQueryBuilder('te')
            ->select('COALESCE(SUM(te.durationMinutes), 0)')
            ->andWhere('te.task = :task')->setParameter('task', $taskId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Total tracked minutes across the given projects since a point in time —
     * used by the portal dashboard's retainer-budget tile (consumed this month).
     *
     * @param list<Project> $projects
     */
    public function sumMinutesForProjectsSince(array $projects, \DateTimeImmutable $since): int
    {
        if ($projects === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('te')
            ->select('COALESCE(SUM(te.durationMinutes), 0)')
            ->andWhere('te.project IN (:projects)')
            ->andWhere('te.startsAt >= :since')
            ->setParameter('projects', $projects)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
