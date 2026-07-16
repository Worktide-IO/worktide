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
    /**
     * The rfc4122 ids of every user who is a member of the given workspace.
     * Used to label the "Worktide Team" on the shared feedback board (members
     * of the platform feedback workspace).
     *
     * @return list<string>
     */
    public function userIdsForWorkspace(Workspace $workspace): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.user) AS uid')
            ->andWhere('m.workspace = :workspace')->setParameter('workspace', $workspace)
            ->getQuery()
            ->getResult();

        $ids = [];
        foreach ($rows as $row) {
            $uid = $row['uid'];
            if ($uid instanceof \Symfony\Component\Uid\Uuid) {
                $ids[] = $uid->toRfc4122();
            } elseif (\is_string($uid)) {
                // IDENTITY() may come back as raw binary or a string form.
                $ids[] = \strlen($uid) === 16 ? \Symfony\Component\Uid\Uuid::fromBinary($uid)->toRfc4122() : $uid;
            }
        }

        return $ids;
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
