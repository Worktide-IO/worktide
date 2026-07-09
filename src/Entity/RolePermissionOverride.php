<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\Capability;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\RolePermissionOverrideRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Workspace-scoped override of the DefaultPermissions matrix.
 *
 * Each row says: in workspace X, role R has capability C with value
 * `isGranted` (which can be true OR false — overrides can both grant AND
 * revoke). The {@see PermissionResolver} consults overrides first and only
 * falls back to {@see \App\Security\DefaultPermissions} when no row matches.
 *
 * The unique constraint guarantees one override per (workspace, role,
 * capability) tuple — re-asserting the same triple updates the existing row
 * rather than stacking conflicting policies.
 *
 * Owner is intentionally excluded as a target role here (validation): no
 * override should be able to lock the owner out of their own workspace.
 */
#[ORM\Entity(repositoryClass: RolePermissionOverrideRepository::class)]
#[ORM\Table(name: 'role_permission_overrides')]
#[ORM\UniqueConstraint(
    name: 'role_permission_override_unique',
    columns: ['workspace_id', 'role', 'capability'],
)]
#[ORM\Index(name: 'role_permission_override_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'RolePermissionOverride',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        // securityPostDenormalize (not security) so the check runs AFTER the
        // `workspace` field is populated — otherwise object.getWorkspace() is
        // null and any member could grant capabilities in ANY workspace.
        new Post(securityPostDenormalize: "is_granted('MANAGE', object.getWorkspace())"),
        new Patch(security: "is_granted('MANAGE', object.getWorkspace())"),
        new Delete(security: "is_granted('MANAGE', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'role' => 'exact',
    'capability' => 'exact',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isGranted'])]
class RolePermissionOverride
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 16, enumType: WorkspaceMemberRole::class)]
    #[Assert\NotEqualTo(
        value: WorkspaceMemberRole::Owner,
        message: 'Owner capabilities are immutable and cannot be overridden.',
    )]
    private WorkspaceMemberRole $role;

    #[ORM\Column(length: 80, enumType: Capability::class)]
    private Capability $capability;

    #[ORM\Column]
    private bool $isGranted = true;

    public function getRole(): WorkspaceMemberRole { return $this->role; }
    public function setRole(WorkspaceMemberRole $role): self { $this->role = $role; return $this; }

    public function getCapability(): Capability { return $this->capability; }
    public function setCapability(Capability $cap): self { $this->capability = $cap; return $this; }

    public function isGranted(): bool { return $this->isGranted; }
    public function setIsGranted(bool $v): self { $this->isGranted = $v; return $this; }
}
