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
use App\Repository\TaskListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Kanban-style "lane" / "column" inside a Project. Mirrors awork's TaskList:
 * a Task can sit in MULTIPLE TaskLists (e.g. one Kanban view per quarter +
 * one priority view) — membership + per-list ordering live in TaskListEntry.
 *
 * Voter delegates to the parent Project: anyone with VIEW on the Project
 * sees the list; EDIT to mutate.
 */
#[ORM\Entity(repositoryClass: TaskListRepository::class)]
#[ORM\Table(name: 'task_lists')]
#[ORM\Index(name: 'task_list_project_idx', columns: ['project_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'TaskList',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object)"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object)"),
        new Delete(security: "is_granted('DELETE', object)"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'project' => 'exact',
    'name' => 'partial',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isArchived', 'isHiddenForConnectUsers'])]
#[ApiFilter(OrderFilter::class, properties: ['position', 'name', 'createdAt'])]
class TaskList
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne(inversedBy: 'taskLists')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $color = null;

    /** Fractional position so reorder operations don't need to renumber peers. */
    #[ORM\Column(type: 'float')]
    private float $position = 0.0;

    #[ORM\Column]
    private bool $isArchived = false;

    #[ORM\Column]
    private bool $isHiddenForConnectUsers = false;

    /** @var Collection<int, TaskListEntry> */
    #[ORM\OneToMany(targetEntity: TaskListEntry::class, mappedBy: 'list', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $entries;

    public function __construct()
    {
        $this->entries = new ArrayCollection();
    }

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $p): self { $this->project = $p; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function getPosition(): float { return $this->position; }
    public function setPosition(float $p): self { $this->position = $p; return $this; }

    public function isArchived(): bool { return $this->isArchived; }
    public function setIsArchived(bool $v): self { $this->isArchived = $v; return $this; }

    public function isHiddenForConnectUsers(): bool { return $this->isHiddenForConnectUsers; }
    public function setIsHiddenForConnectUsers(bool $v): self { $this->isHiddenForConnectUsers = $v; return $this; }

    /** @return Collection<int, TaskListEntry> */
    public function getEntries(): Collection { return $this->entries; }
}
