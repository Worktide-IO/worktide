<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
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
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\ExternalReferenceTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'projects')]
#[ORM\UniqueConstraint(name: 'project_workspace_key_unique', columns: ['workspace_id', 'project_key'])]
#[ORM\Index(name: 'project_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Project',
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
    'name' => 'partial',
    'key' => 'exact',
    'description' => 'partial',
    'workspace' => 'exact',
    'status' => 'exact',
    'projectType' => 'exact',
    'owner' => 'exact',
    'tags' => 'exact',
    'customer' => 'exact',
    'members.user' => 'exact',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isArchived', 'isPrivate', 'isRetainer', 'isMultiAssignmentAllowed', 'isBillableByDefault', 'isExternal', 'isProjectKeyVisible'])]
#[ApiFilter(DateFilter::class, properties: ['startsOn', 'dueOn', 'createdAt', 'updatedAt'])]
#[ApiFilter(ExistsFilter::class, properties: ['deletedAt', 'owner', 'dueOn', 'customer'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'key', 'createdAt', 'updatedAt', 'dueOn', 'startsOn'])]
class Project
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;
    use ExternalReferenceTrait;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(name: 'project_key', length: 16)]
    private string $key;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 16)]
    private string $color = '#6366f1';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ProjectStatus $status;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProjectType $projectType = null;

    /**
     * The customer this project is for. Nullable because some projects are
     * internal (no client to invoice). Replaces awork's `Company` FK with
     * a link to our first-class CRM customer record.
     */
    #[ORM\ManyToOne(inversedBy: 'projects')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\Column]
    private bool $isPrivate = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedOn = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $closedBy = null;

    #[ORM\Column]
    private bool $isBillableByDefault = true;

    #[ORM\Column]
    private bool $deductNonBillableHours = false;

    #[ORM\Column]
    private bool $hasImage = false;

    #[ORM\Column]
    private bool $isRetainer = false;

    #[ORM\Column]
    private bool $isMultiAssignmentAllowed = true;

    /**
     * Connect-Project: visible to external (cross-workspace) members and
     * the upcoming Customer-Portal. Hidden tasks
     * (Task.isHiddenForConnectUsers) stay invisible regardless.
     */
    #[ORM\Column]
    private bool $isExternal = false;

    /**
     * Controls whether the human-readable key (`ACME-123`) renders on
     * task cards and lists. Some teams find it noisy.
     */
    #[ORM\Column]
    private bool $isProjectKeyVisible = true;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Workflow $workflow = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsOn = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dueOn = null;

    #[ORM\Column]
    private bool $isArchived = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $budgetMinutes = null;

    /** @var Collection<int, ProjectMember> */
    #[ORM\OneToMany(targetEntity: ProjectMember::class, mappedBy: 'project', orphanRemoval: true)]
    private Collection $members;

    /** @var Collection<int, Task> */
    #[ORM\OneToMany(targetEntity: Task::class, mappedBy: 'project', orphanRemoval: true)]
    private Collection $tasks;

    /** @var Collection<int, TaskList> */
    #[ORM\OneToMany(targetEntity: TaskList::class, mappedBy: 'project', orphanRemoval: true)]
    private Collection $taskLists;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'project_tags')]
    private Collection $tags;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->tasks = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->taskLists = new ArrayCollection();
    }

    /** @return Collection<int, TaskList> */
    public function getTaskLists(): Collection { return $this->taskLists; }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = strtoupper($key);
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

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function getStatus(): ProjectStatus
    {
        return $this->status;
    }

    public function setStatus(ProjectStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): self
    {
        $this->owner = $owner;
        return $this;
    }

    public function getStartsOn(): ?\DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function setStartsOn(?\DateTimeImmutable $startsOn): self
    {
        $this->startsOn = $startsOn;
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

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function setIsArchived(bool $value): self
    {
        $this->isArchived = $value;
        return $this;
    }

    public function getBudgetMinutes(): ?int
    {
        return $this->budgetMinutes;
    }

    public function setBudgetMinutes(?int $budgetMinutes): self
    {
        $this->budgetMinutes = $budgetMinutes;
        return $this;
    }

    public function getProjectType(): ?ProjectType
    {
        return $this->projectType;
    }

    public function setProjectType(?ProjectType $type): self
    {
        $this->projectType = $type;
        return $this;
    }

    public function getCustomer(): ?Customer { return $this->customer; }
    public function setCustomer(?Customer $c): self { $this->customer = $c; return $this; }

    public function isPrivate(): bool { return $this->isPrivate; }
    public function setIsPrivate(bool $v): self { $this->isPrivate = $v; return $this; }

    public function getClosedOn(): ?\DateTimeImmutable { return $this->closedOn; }
    public function setClosedOn(?\DateTimeImmutable $when): self { $this->closedOn = $when; return $this; }

    public function getClosedBy(): ?User { return $this->closedBy; }
    public function setClosedBy(?User $user): self { $this->closedBy = $user; return $this; }

    public function close(User $by): self
    {
        $this->closedOn = new \DateTimeImmutable();
        $this->closedBy = $by;
        return $this;
    }

    public function reopen(): self
    {
        $this->closedOn = null;
        $this->closedBy = null;
        return $this;
    }

    public function isBillableByDefault(): bool { return $this->isBillableByDefault; }
    public function setIsBillableByDefault(bool $v): self { $this->isBillableByDefault = $v; return $this; }

    public function isDeductNonBillableHours(): bool { return $this->deductNonBillableHours; }
    public function setDeductNonBillableHours(bool $v): self { $this->deductNonBillableHours = $v; return $this; }

    public function hasImage(): bool { return $this->hasImage; }
    public function setHasImage(bool $v): self { $this->hasImage = $v; return $this; }

    public function isRetainer(): bool { return $this->isRetainer; }
    public function setIsRetainer(bool $v): self { $this->isRetainer = $v; return $this; }

    public function isMultiAssignmentAllowed(): bool { return $this->isMultiAssignmentAllowed; }
    public function setIsMultiAssignmentAllowed(bool $v): self { $this->isMultiAssignmentAllowed = $v; return $this; }

    public function isExternal(): bool { return $this->isExternal; }
    public function setIsExternal(bool $v): self { $this->isExternal = $v; return $this; }

    public function isProjectKeyVisible(): bool { return $this->isProjectKeyVisible; }
    public function setIsProjectKeyVisible(bool $v): self { $this->isProjectKeyVisible = $v; return $this; }

    public function getWorkflow(): ?Workflow { return $this->workflow; }
    public function setWorkflow(?Workflow $workflow): self { $this->workflow = $workflow; return $this; }

    /** @return Collection<int, ProjectMember> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    /** @return Collection<int, Task> */
    public function getTasks(): Collection
    {
        return $this->tasks;
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
