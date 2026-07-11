<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\ProjectMemberRole;
use App\Entity\Project;
use App\Entity\ProjectShare;
use App\Entity\User;
use App\Entity\WorkspaceMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectShare>
 */
class ProjectShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectShare::class);
    }

    /**
     * The collaboration role a user has on a project via a cross-workspace
     * share — i.e. the project is shared into a workspace the user belongs to.
     * Null when there is no such share. Used by the voters to grant shared
     * access on top of host-workspace / project-member access.
     */
    public function findRoleForUser(Project $project, User $user): ?ProjectMemberRole
    {
        /** @var list<ProjectShare> $rows */
        $rows = $this->createQueryBuilder('ps')
            ->innerJoin(WorkspaceMember::class, 'wm', Join::WITH, 'wm.workspace = ps.sharedWithWorkspace')
            ->where('ps.project = :project')
            ->andWhere('wm.user = :user')
            ->andWhere('wm.isActive = true')
            ->setParameter('project', $project)
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $rows === [] ? null : $rows[0]->getRole();
    }
}
