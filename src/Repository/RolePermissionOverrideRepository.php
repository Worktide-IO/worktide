<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\Capability;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\RolePermissionOverride;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RolePermissionOverride>
 */
class RolePermissionOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RolePermissionOverride::class);
    }

    public function findOverride(
        Workspace $workspace,
        WorkspaceMemberRole $role,
        Capability $capability,
    ): ?RolePermissionOverride {
        return $this->findOneBy([
            'workspace' => $workspace,
            'role' => $role,
            'capability' => $capability,
        ]);
    }

    /**
     * @return list<RolePermissionOverride>
     */
    public function findForWorkspace(Workspace $workspace): array
    {
        return $this->findBy(['workspace' => $workspace]);
    }
}
