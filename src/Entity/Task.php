<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\TaskCreatedVia;
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
#[ORM\Index(name: 'task_correlation_idx', columns: ['correlation_id'])]
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
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'identifier' => 'exact',
    'title' => 'partial',
    'description' => 'partial',
    'workspace' => 'exact',
    'project' => 'exact',
    'status' => 'exact',
    'priority' => 'exact',
    'tracker' => 'exact',
    'fixedVersion' => 'exact',
    'sprint' => 'exact',
    'assignedPrincipals.principalId' => 'exact',
    'createdBy' => 'exact',
    'parent' => 'exact',
    'tags' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['dueOn', 'startOn', 'scheduledEnd', 'startedOn', 'closedOn', 'createdAt', 'updatedAt'])]
#[ApiFilter(ExistsFilter::class, properties: ['deletedAt', 'dueOn', 'startOn', 'scheduledEnd', 'parent', 'closedOn'])]
#[ApiFilter(\ApiPlatform\Doctrine\Orm\Filter\BooleanFilter::class, properties: ['isPrio', 'isHiddenForConnectUsers'])]
#[ApiFilter(OrderFilter::class, properties: ['identifier', 'title', 'priority', 'position', 'dueOn', 'createdAt', 'updatedAt', 'priorityScore'])]
#[ApiFilter(RangeFilter::class, properties: ['priorityScore'])]
class Task
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;
    use ExternalReferenceTrait;

    /**
     * Tasks without a project ("private tasks") are personal todos that belong
     * to a workspace but don't show up in any project board. They follow the
     * same auth rules — owner-only visibility — via the Voter, and let users
     * track miscellaneous work alongside structured project tasks.
     */
    #[ORM\ManyToOne(inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Project $project = null;

    /** Conversation this task was created from (Phase C Schicht 4), if any. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Conversation $sourceConversation = null;

    #[ORM\Column(length: 24)]
    private string $identifier;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private TaskStatus $status;

    /**
     * Issue-type classification (Bug / Feature / Story / Support / …).
     * Nullable because workspaces that don't care about issue typing
     * can ignore the dimension entirely; the SPA just hides the chip.
     *
     * onDelete=RESTRICT — deleting a Tracker that's still in use must
     * fail loudly so the admin reassigns first. The /workspace-settings
     * UI surfaces the reassign flow before offering Delete.
     */
    #[ORM\ManyToOne(targetEntity: Tracker::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Tracker $tracker = null;

    /**
     * The Release/ProjectVersion this task is targeted at. Nullable —
     * tasks not yet scheduled into a release stay unset.
     *
     * onDelete=SET NULL — deleting a Release frees its tasks rather
     * than cascading them away, so admins can clean up release lists
     * without losing work.
     */
    #[ORM\ManyToOne(targetEntity: ProjectVersion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProjectVersion $fixedVersion = null;

    /**
     * The Sprint this task is committed to, or null = backlog. Deleting a
     * sprint frees its tasks back to the backlog (SET NULL) rather than
     * cascading them away — same rationale as fixedVersion above.
     */
    #[ORM\ManyToOne(targetEntity: Sprint::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Sprint $sprint = null;

    #[ORM\Column(length: 12, enumType: TaskPriority::class)]
    private TaskPriority $priority = TaskPriority::Normal;

    /**
     * Polymorphic assignment principals — a mix of Users and Teams.
     * Replaces the old `task_assignees` ManyToMany; the flat user-IRI
     * list exposed as `assignees` in the API is derived from this
     * collection plus expansion of team members (see
     * `getAssignees()` below).
     *
     * @var Collection<int, TaskAssignee>
     */
    #[ORM\OneToMany(targetEntity: TaskAssignee::class, mappedBy: 'task', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $assignedPrincipals;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dueOn = null;

    /**
     * Planned start (Soll). Used by Gantt and the AI auto-scheduler in
     * Phase D — separate from `startedOn` (Ist, set when work begins).
     *
     * Also serves as the scheduled-start for the Team-Planner view
     * (Phase B.4): when both `startOn` and `scheduledEnd` are set,
     * the task renders as a draggable time-block on the planner grid.
     * When only `startOn` is set, the planner falls back to a single
     * "starts at" marker without an explicit end.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startOn = null;

    /**
     * Planned end (Soll) — companion to `startOn` for the Team-Planner.
     * If null, the planner derives a fallback end from
     * `estimatedMinutes` (or treats the slot as 30 min wide).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $scheduledEnd = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedOn = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $estimatedMinutes = null;

    /**
     * Materialized internal priority score (WSJF-lite, 0–100) — a computed
     * signal, not manually editable. Written by worktide:priority:recompute
     * (see {@see \App\Service\Priority\PriorityScoreCalculator}); read-only over
     * the API so it can be sorted/filtered server-side and shipped in the row
     * payload without a separate reports fetch.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[ApiProperty(writable: false)]
    private ?int $priorityScore = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[ApiProperty(writable: false)]
    private bool $priorityScoreBlocked = false;

    /** @var array<int, array{label: string, contribution: int}>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    #[ApiProperty(writable: false)]
    private ?array $priorityScoreParts = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[ApiProperty(writable: false)]
    private ?\DateTimeImmutable $priorityScoreAt = null;

    /**
     * Idempotency anchor for inbound channels (CSV-Import, Mail-Inbound,
     * Webhook-Replay, AI-Breakdown). Indexed but not unique — multiple
     * channels can collide deliberately on the same external reference.
     * Callers that need uniqueness must check before insert.
     */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?\Symfony\Component\Uid\Uuid $correlationId = null;

    /**
     * How this task entered Worktide. Default is direct creation through
     * the UI; importers, mail handlers, and automations stamp their own
     * value so the activity feed and reports can attribute volume.
     */
    #[ORM\Column(length: 16, enumType: TaskCreatedVia::class)]
    private TaskCreatedVia $createdVia = TaskCreatedVia::Created;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Task $parent = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $isPrio = false;

    /**
     * Internal task that must not be visible to external (cross-workspace)
     * project members. Same semantics as Comment.isHiddenForConnectUsers.
     */
    #[ORM\Column]
    private bool $isHiddenForConnectUsers = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedOn = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $closedBy = null;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'task_tags')]
    private Collection $tags;

    /** @var Collection<int, TaskListEntry> */
    #[ORM\OneToMany(targetEntity: TaskListEntry::class, mappedBy: 'task', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $listEntries;

    /** @var Collection<int, ChecklistItem> */
    #[ORM\OneToMany(targetEntity: ChecklistItem::class, mappedBy: 'task', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $checklistItems;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
        $this->assignedPrincipals = new ArrayCollection();
        $this->listEntries = new ArrayCollection();
        $this->checklistItems = new ArrayCollection();
    }

    /** @return Collection<int, TaskListEntry> */
    public function getListEntries(): Collection { return $this->listEntries; }

    /** @return Collection<int, ChecklistItem> */
    public function getChecklistItems(): Collection { return $this->checklistItems; }

    /** Number of checklist items on this task (for awork-style task summaries). */
    public function getChecklistItemsCount(): int
    {
        return $this->checklistItems->count();
    }

    /** Number of checked-off checklist items. */
    public function getChecklistItemsDoneCount(): int
    {
        $done = 0;
        foreach ($this->checklistItems as $item) {
            if ($item->isDone()) {
                $done++;
            }
        }
        return $done;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): self
    {
        $this->project = $project;
        return $this;
    }

    public function getSourceConversation(): ?Conversation
    {
        return $this->sourceConversation;
    }

    public function setSourceConversation(?Conversation $conversation): self
    {
        $this->sourceConversation = $conversation;
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

    /**
     * Structured principal collection. Use this in code that needs to
     * distinguish between direct user assignments and team assignments
     * (e.g. the UI's "assigned teams" badge).
     *
     * @return Collection<int, TaskAssignee>
     */
    public function getAssignedPrincipals(): Collection
    {
        return $this->assignedPrincipals;
    }

    public function addAssignedPrincipal(TaskAssignee $p): self
    {
        if (!$this->assignedPrincipals->contains($p)) {
            $this->assignedPrincipals->add($p);
            $p->setTask($this);
        }
        return $this;
    }

    public function removeAssignedPrincipal(TaskAssignee $p): self
    {
        $this->assignedPrincipals->removeElement($p);
        return $this;
    }

    /**
     * Backwards-compatible flat list of *directly* assigned user IRIs.
     *
     * Mirrors the legacy `assignees: string[]` API field clients have
     * always seen. Team expansion (showing each team's members in the
     * avatar stack) lives in a service layer — the entity here just
     * surfaces the literal user-principals, no team-member walk.
     *
     * Use `getAssignedPrincipals()` when code needs to distinguish team
     * vs user assignments; use `getAssignedTeamIris()` for the parallel
     * team-IRI list.
     *
     * @return list<string>
     */
    public function getAssignees(): array
    {
        $out = [];
        foreach ($this->assignedPrincipals as $p) {
            \assert($p instanceof TaskAssignee);
            if ($p->getPrincipalType() === \App\Entity\Enum\AssigneePrincipalType::User) {
                $out[] = '/v1/users/' . $p->getPrincipalId()->toRfc4122();
            }
        }
        return $out;
    }

    /**
     * Team IRIs directly assigned to this task. The SPA may render
     * these as a separate "team chip" group or expand via a parallel
     * /v1/teams fetch.
     *
     * @return list<string>
     */
    public function getAssignedTeams(): array
    {
        $out = [];
        foreach ($this->assignedPrincipals as $p) {
            \assert($p instanceof TaskAssignee);
            if ($p->getPrincipalType() === \App\Entity\Enum\AssigneePrincipalType::Team) {
                $out[] = '/v1/teams/' . $p->getPrincipalId()->toRfc4122();
            }
        }
        return $out;
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

    public function getStartOn(): ?\DateTimeImmutable
    {
        return $this->startOn;
    }

    public function setStartOn(?\DateTimeImmutable $startOn): self
    {
        $this->startOn = $startOn;
        return $this;
    }

    public function getScheduledEnd(): ?\DateTimeImmutable
    {
        return $this->scheduledEnd;
    }

    public function setScheduledEnd(?\DateTimeImmutable $scheduledEnd): self
    {
        $this->scheduledEnd = $scheduledEnd;
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

    public function getCorrelationId(): ?\Symfony\Component\Uid\Uuid
    {
        return $this->correlationId;
    }

    public function setCorrelationId(?\Symfony\Component\Uid\Uuid $correlationId): self
    {
        $this->correlationId = $correlationId;
        return $this;
    }

    public function getCreatedVia(): TaskCreatedVia
    {
        return $this->createdVia;
    }

    public function setCreatedVia(TaskCreatedVia $createdVia): self
    {
        $this->createdVia = $createdVia;
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

    public function getPriorityScore(): ?int
    {
        return $this->priorityScore;
    }

    public function setPriorityScore(?int $score): self
    {
        $this->priorityScore = $score;
        return $this;
    }

    public function isPriorityScoreBlocked(): bool
    {
        return $this->priorityScoreBlocked;
    }

    public function setPriorityScoreBlocked(bool $blocked): self
    {
        $this->priorityScoreBlocked = $blocked;
        return $this;
    }

    /** @return array<int, array{label: string, contribution: int}>|null */
    public function getPriorityScoreParts(): ?array
    {
        return $this->priorityScoreParts;
    }

    /** @param array<int, array{label: string, contribution: int}>|null $parts */
    public function setPriorityScoreParts(?array $parts): self
    {
        $this->priorityScoreParts = $parts;
        return $this;
    }

    public function getPriorityScoreAt(): ?\DateTimeImmutable
    {
        return $this->priorityScoreAt;
    }

    public function setPriorityScoreAt(?\DateTimeImmutable $at): self
    {
        $this->priorityScoreAt = $at;
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

    public function isPrio(): bool { return $this->isPrio; }
    public function setIsPrio(bool $v): self { $this->isPrio = $v; return $this; }

    public function getTracker(): ?Tracker { return $this->tracker; }
    public function setTracker(?Tracker $t): self { $this->tracker = $t; return $this; }

    public function getSprint(): ?Sprint { return $this->sprint; }
    public function setSprint(?Sprint $sprint): self { $this->sprint = $sprint; return $this; }

    public function getFixedVersion(): ?ProjectVersion { return $this->fixedVersion; }
    public function setFixedVersion(?ProjectVersion $v): self { $this->fixedVersion = $v; return $this; }

    public function isHiddenForConnectUsers(): bool { return $this->isHiddenForConnectUsers; }
    public function setIsHiddenForConnectUsers(bool $v): self { $this->isHiddenForConnectUsers = $v; return $this; }

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
