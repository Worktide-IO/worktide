<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\TeamRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Workspace-scoped grouping of users — "Design Team", "DevOps", etc.
 * M:N to User (membership) and M:N to Project (which projects this team works on).
 *
 * Team membership is independent of WorkspaceMember/ProjectMember roles —
 * it's a soft grouping for reporting, mentions, and capacity planning.
 */
#[ORM\Entity(repositoryClass: TeamRepository::class)]
#[ORM\Table(name: 'teams')]
#[ORM\UniqueConstraint(name: 'team_workspace_name_unique', columns: ['workspace_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Team',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'name' => 'partial', 'members' => 'exact', 'projects' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['isArchived'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
class Team
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $color = null;

    #[ORM\Column]
    private bool $isArchived = false;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'team_members')]
    private Collection $members;

    /** @var Collection<int, Project> */
    #[ORM\ManyToMany(targetEntity: Project::class)]
    #[ORM\JoinTable(name: 'team_projects')]
    private Collection $projects;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->projects = new ArrayCollection();
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $desc): self { $this->description = $desc; return $this; }

    public function getIcon(): ?string { return $this->icon; }
    public function setIcon(?string $icon): self { $this->icon = $icon; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function isArchived(): bool { return $this->isArchived; }
    public function setIsArchived(bool $v): self { $this->isArchived = $v; return $this; }

    /** @return Collection<int, User> */
    public function getMembers(): Collection { return $this->members; }
    public function addMember(User $user): self
    {
        if (!$this->members->contains($user)) { $this->members->add($user); }
        return $this;
    }
    public function removeMember(User $user): self { $this->members->removeElement($user); return $this; }

    /** @return Collection<int, Project> */
    public function getProjects(): Collection { return $this->projects; }
    public function addProject(Project $project): self
    {
        if (!$this->projects->contains($project)) { $this->projects->add($project); }
        return $this;
    }
    public function removeProject(Project $project): self { $this->projects->removeElement($project); return $this; }
}
