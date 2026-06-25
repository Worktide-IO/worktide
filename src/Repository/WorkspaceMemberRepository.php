<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkspaceMember>
 */
class WorkspaceMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkspaceMember::class);
    }

    /**
     * Find the workspace member whose user has the given email (case-insensitive),
     * or null. Used by {@see \App\Service\Inbound\InboundImportFilter} to match an
     * external participant onto a workspace person by email.
     */
    public function findByWorkspaceAndEmail(Workspace $workspace, string $email): ?WorkspaceMember
    {
        return $this->createQueryBuilder('m')
            ->join('m.user', 'u')
            ->andWhere('m.workspace = :workspace')->setParameter('workspace', $workspace)
            ->andWhere('LOWER(u.email) = LOWER(:email)')->setParameter('email', $email)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
