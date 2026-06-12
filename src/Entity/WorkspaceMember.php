<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\WorkspaceMemberRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkspaceMemberRepository::class)]
#[ORM\Table(name: 'workspace_members')]
#[ORM\UniqueConstraint(name: 'workspace_user_unique', columns: ['workspace_id', 'user_id'])]
#[ORM\HasLifecycleCallbacks]
class WorkspaceMember
{
    use EntityIdTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne(inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\ManyToOne(inversedBy: 'workspaceMemberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: WorkspaceMemberRole::class)]
    private WorkspaceMemberRole $role = WorkspaceMemberRole::Member;

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
}
