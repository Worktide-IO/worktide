<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\TaskPriority;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\ExternalReferenceTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\TaskRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'tasks')]
#[ORM\Index(name: 'task_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'task_project_idx', columns: ['project_id'])]
#[ORM\Index(name: 'task_assignee_idx', columns: ['assignee_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Task',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object)"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object)"),
        new Delete(security: "is_granted('DELETE', object)"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'identifier' => 'exact',
    'title' => 'partial',
    'description' => 'partial',
    'workspace' => 'exact',
    'project' => 'exact',
    'status' => 'exact',
    'priority' => 'exact',
    'assignee' => 'exact',
    'createdBy' => 'exact',
    'parent' => 'exact',
    'tags' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['dueOn', 'startedOn', 'createdAt', 'updatedAt'])]
#[ApiFilter(ExistsFilter::class, properties: ['deletedAt', 'assignee', 'dueOn', 'parent'])]
#[ApiFilter(OrderFilter::class, properties: ['identifier', 'title', 'priority', 'position', 'dueOn', 'createdAt', 'updatedAt'])]
class Task
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;
    use ExternalReferenceTrait;

    #[ORM\ManyToOne(inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 24)]
    private string $identifier;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private TaskStatus $status;

    #[ORM\Column(length: 12, enumType: TaskPriority::class)]
    private TaskPriority $priority = TaskPriority::Normal;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignee = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dueOn = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedOn = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $estimatedMinutes = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Task $parent = null;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'task_tags')]
    private Collection $tags;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function setProject(Project $project): self
    {
        $this->project = $project;
        return $this;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): self
    {
        $this->identifier = $identifier;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getStatus(): TaskStatus
    {
        return $this->status;
    }

    public function setStatus(TaskStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getPriority(): TaskPriority
    {
        return $this->priority;
    }

    public function setPriority(TaskPriority $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    public function getAssignee(): ?User
    {
        return $this->assignee;
    }

    public function setAssignee(?User $assignee): self
    {
        $this->assignee = $assignee;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $user): self
    {
        $this->createdBy = $user;
        return $this;
    }

    public function getDueOn(): ?\DateTimeImmutable
    {
        return $this->dueOn;
    }

    public function setDueOn(?\DateTimeImmutable $dueOn): self
    {
        $this->dueOn = $dueOn;
        return $this;
    }

    public function getStartedOn(): ?\DateTimeImmutable
    {
        return $this->startedOn;
    }

    public function setStartedOn(?\DateTimeImmutable $startedOn): self
    {
        $this->startedOn = $startedOn;
        return $this;
    }

    public function getEstimatedMinutes(): ?int
    {
        return $this->estimatedMinutes;
    }

    public function setEstimatedMinutes(?int $minutes): self
    {
        $this->estimatedMinutes = $minutes;
        return $this;
    }

    public function getParent(): ?Task
    {
        return $this->parent;
    }

    public function setParent(?Task $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;
        return $this;
    }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): self
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
        return $this;
    }

    public function removeTag(Tag $tag): self
    {
        $this->tags->removeElement($tag);
        return $this;
    }
}
