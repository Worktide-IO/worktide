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
use App\Entity\Enum\ProjectMemberRole;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\ProjectMemberRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectMemberRepository::class)]
#[ORM\Table(name: 'project_members')]
#[ORM\UniqueConstraint(name: 'project_user_unique', columns: ['project_id', 'user_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ProjectMember',
    operations: [
        // Collection is scoped to the caller's workspaces by
        // WorkspaceScopeExtension (via .project.workspace); item + write ops are
        // gated on the parent project so members of one workspace can't read or
        // tamper with another workspace's project memberships (which would grant
        // task access via TaskVoter).
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getProject())"),
        new Post(securityPostDenormalize: "is_granted('EDIT', object.getProject())"),
        new Patch(security: "is_granted('EDIT', object.getProject())"),
        new Delete(security: "is_granted('EDIT', object.getProject())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['project' => 'exact', 'user' => 'exact', 'role' => 'exact'])]
class ProjectMember
{
    use EntityIdTrait;
    use TimestampableTrait;
    use AuditableTrait;

    #[ORM\ManyToOne(inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: ProjectMemberRole::class)]
    private ProjectMemberRole $role = ProjectMemberRole::Contributor;

    public function getProject(): Project
    {
        return $this->project;
    }

    public function setProject(Project $project): self
    {
        $this->project = $project;
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

    public function getRole(): ProjectMemberRole
    {
        return $this->role;
    }

    public function setRole(ProjectMemberRole $role): self
    {
        $this->role = $role;
        return $this;
    }
}
