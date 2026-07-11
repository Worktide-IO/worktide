<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\WorkspaceMemberRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkspaceMemberRepository::class)]
#[ORM\Table(name: 'workspace_members')]
#[ORM\UniqueConstraint(name: 'workspace_user_unique', columns: ['workspace_id', 'user_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'WorkspaceMember',
    operations: [
        // Collection reads are scoped to the caller's workspaces by
        // WorkspaceScopeExtension (WorkspaceMember has a `.workspace`).
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        // securityPostDenormalize so object.getWorkspace() is populated before
        // the check — only a workspace owner (MANAGE) may add/change members.
        new Post(securityPostDenormalize: "is_granted('MANAGE', object.getWorkspace())"),
        new Patch(security: "is_granted('MANAGE', object.getWorkspace())"),
        new Delete(security: "is_granted('MANAGE', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'user' => 'exact', 'role' => 'exact'])]
class WorkspaceMember
{
    use EntityIdTrait;
    use TimestampableTrait;
    use AuditableTrait;

    #[ORM\ManyToOne(inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\ManyToOne(inversedBy: 'workspaceMemberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: WorkspaceMemberRole::class)]
    private WorkspaceMemberRole $role = WorkspaceMemberRole::Member;

    /**
     * A deactivated member keeps their row (history, re-activation) but is
     * treated as a non-member for access: WorkspaceScopeExtension and every
     * membership-based voter ignore rows where this is false, so the user can
     * no longer see or touch anything in this workspace until re-enabled.
     * Toggled via PATCH /v1/workspace_members/{id} (MANAGE), like `role`.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function setWorkspace(Workspace $workspace): self
    {
        $this->workspace = $workspace;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getRole(): WorkspaceMemberRole
    {
        return $this->role;
    }

    public function setRole(WorkspaceMemberRole $role): self
    {
        $this->role = $role;
        return $this;
    }

    // Named getIsActive() (not isActive()) so API Platform / Symfony PropertyInfo
    // maps it to the `isActive` property — an `isActive()` accessor would bind to
    // a phantom `active` property and the field would silently vanish from the
    // serialized response.
    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }
}
