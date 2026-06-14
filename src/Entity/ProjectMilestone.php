<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
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
use App\Repository\ProjectMilestoneRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named goal/checkpoint inside a Project — typically tied to a due date,
 * with an optional set of tasks whose completion marks it as reached.
 *
 * Going beyond awork (which only has id/name/color/dueDate):
 *  - `isReached` + `reachedOn` + `reachedBy` so the activity feed knows when
 *  - `isArchived` to hide stale milestones without losing history
 *  - M:N to Task — "what needs to be done for this milestone"
 *  - `description` + `position` for richer UI
 */
#[ORM\Entity(repositoryClass: ProjectMilestoneRepository::class)]
#[ORM\Table(name: 'project_milestones')]
#[ORM\Index(name: 'milestone_project_idx', columns: ['project_id'])]
#[ORM\Index(name: 'milestone_due_idx', columns: ['due_on'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ProjectMilestone',
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
#[ApiFilter(BooleanFilter::class, properties: ['isReached', 'isArchived'])]
#[ApiFilter(DateFilter::class, properties: ['dueOn', 'reachedOn'])]
#[ApiFilter(OrderFilter::class, properties: ['dueOn', 'position', 'name'])]
class ProjectMilestone
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 16)]
    private string $color = '#a78bfa';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dueOn = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $isReached = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reachedOn = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reachedBy = null;

    #[ORM\Column]
    private bool $isArchived = false;

    /** @var Collection<int, Task> tasks whose completion contributes to reaching this milestone */
    #[ORM\ManyToMany(targetEntity: Task::class)]
    #[ORM\JoinTable(name: 'project_milestone_tasks')]
    private Collection $tasks;

    public function __construct()
    {
        $this->tasks = new ArrayCollection();
    }

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $project): self { $this->project = $project; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $color): self { $this->color = $color; return $this; }

    public function getDueOn(): ?\DateTimeImmutable { return $this->dueOn; }
    public function setDueOn(?\DateTimeImmutable $dueOn): self { $this->dueOn = $dueOn; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    public function isReached(): bool { return $this->isReached; }
    public function setIsReached(bool $value): self
    {
        if ($value && !$this->isReached) {
            $this->reachedOn = new \DateTimeImmutable();
        } elseif (!$value && $this->isReached) {
            $this->reachedOn = null;
            $this->reachedBy = null;
        }
        $this->isReached = $value;
        return $this;
    }

    public function getReachedOn(): ?\DateTimeImmutable { return $this->reachedOn; }
    public function getReachedBy(): ?User { return $this->reachedBy; }
    public function setReachedBy(?User $user): self { $this->reachedBy = $user; return $this; }

    public function isArchived(): bool { return $this->isArchived; }
    public function setIsArchived(bool $value): self { $this->isArchived = $value; return $this; }

    /** @return Collection<int, Task> */
    public function getTasks(): Collection { return $this->tasks; }

    public function addTask(Task $task): self
    {
        if (!$this->tasks->contains($task)) {
            $this->tasks->add($task);
        }
        return $this;
    }

    public function removeTask(Task $task): self
    {
        $this->tasks->removeElement($task);
        return $this;
    }
}
