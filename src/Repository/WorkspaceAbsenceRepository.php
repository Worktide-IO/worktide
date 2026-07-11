<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Workspace;
use App\Entity\WorkspaceAbsence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkspaceAbsence>
 */
class WorkspaceAbsenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkspaceAbsence::class);
    }

    /**
     * Workspace-wide closures overlapping [from, to] — company holidays etc. that
     * blank out booking slots for everyone in the workspace.
     *
     * @return list<WorkspaceAbsence>
     */
    public function findForWorkspaceBetween(Workspace $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('wa')
            ->andWhere('wa.workspace = :ws')
            ->andWhere('wa.startsOn <= :to')
            ->andWhere('wa.endsOn >= :from')
            ->setParameter('ws', $workspace)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }
}
