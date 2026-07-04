<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * Tickets visible in the customer portal: tasks in the given (already
     * authorized) projects, excluding those hidden from connect/portal users
     * and soft-deleted ones. Newest first.
     *
     * The caller (PortalAccessResolver / PortalTicketsController) is
     * responsible for passing ONLY projects the contact may see — this method
     * does not itself authorize.
     *
     * @param list<Project> $projects
     * @return list<Task>
     */
    public function findVisiblePortalTickets(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->andWhere('t.project IN (:projects)')
            ->andWhere('t.isHiddenForConnectUsers = false')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('projects', $projects)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
