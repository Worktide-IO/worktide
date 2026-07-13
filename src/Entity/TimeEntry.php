<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter;
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
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\TimeEntryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TimeEntryRepository::class)]
#[ORM\Table(name: 'time_entries')]
#[ORM\Index(name: 'time_entry_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'time_entry_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'time_entry_project_idx', columns: ['project_id'])]
#[ORM\Index(name: 'time_entry_task_idx', columns: ['task_id'])]
#[ORM\Index(name: 'time_entry_starts_at_idx', columns: ['starts_at'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'TimeEntry',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object)"),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "object.getUser() == user or is_granted('EDIT', object)",
        ),
        new Patch(security: "is_granted('EDIT', object)"),
        new Delete(security: "is_granted('DELETE', object)"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'user' => 'exact',
    'project' => 'exact',
    'task' => 'exact',
    'typeOfWork' => 'exact',
    'note' => 'partial',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isBillable', 'isBilled', 'isLocked', 'isExternal'])]
#[ApiFilter(DateFilter::class, properties: ['startsAt', 'endsAt', 'createdAt'])]
#[ApiFilter(ExistsFilter::class, properties: ['task', 'endsAt'])]
#[ApiFilter(RangeFilter::class, properties: ['durationMinutes'])]
#[ApiFilter(OrderFilter::class, properties: ['startsAt', 'endsAt', 'durationMinutes', 'createdAt'])]
class TimeEntry
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;
    use ExternalReferenceTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Nullable to support time tracked against a "private" task (a task that
     * itself has no project FK) and free-form workspace-level time entries
     * without a project pin. Reporting queries that group by project simply
     * bucket null-project rows as "(no project)".
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TypeOfWork $typeOfWork = null;

    /**
     * Awork-style: timesheet entry has been "billed" (invoice sent) — separate
     * concept from isBillable (eligible for billing).
     */
    #[ORM\Column]
    private bool $isBilled = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Task $task = null;

    #[ORM\Column]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(type: 'integer')]
    private int $durationMinutes = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private bool $isBillable = true;

    #[ORM\Column]
    private bool $isLocked = false;

    /**
     * Entry created via an external integration (Connect-Mailbox, awork
     * import, public API). Mirrors Project.isExternal so cross-workspace
     * reports can filter integration noise out.
     */
    #[ORM\Column]
    private bool $isExternal = false;

    /**
     * IANA timezone identifier at the moment of capture
     * (e.g. `Europe/Berlin`, `America/New_York`). Lets the UI render the
     * entry in its original local time even after the user travelled or
     * DST flipped between save and read.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $timezone = null;

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
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

    public function getTypeOfWork(): ?TypeOfWork { return $this->typeOfWork; }
    public function setTypeOfWork(?TypeOfWork $type): self { $this->typeOfWork = $type; return $this; }

    public function isBilled(): bool { return $this->isBilled; }
    public function setIsBilled(bool $v): self { $this->isBilled = $v; return $this; }

    public function getTask(): ?Task
    {
        return $this->task;
    }

    public function setTask(?Task $task): self
    {
        $this->task = $task;
        return $this;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(\DateTimeImmutable $startsAt): self
    {
        $this->startsAt = $startsAt;
        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): self
    {
        $this->endsAt = $endsAt;
        return $this;
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(int $minutes): self
    {
        $this->durationMinutes = $minutes;
        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;
        return $this;
    }

    public function isBillable(): bool
    {
        return $this->isBillable;
    }

    public function setIsBillable(bool $value): self
    {
        $this->isBillable = $value;
        return $this;
    }

    public function isLocked(): bool
    {
        return $this->isLocked;
    }

    public function setIsLocked(bool $value): self
    {
        $this->isLocked = $value;
        return $this;
    }

    public function isExternal(): bool { return $this->isExternal; }
    public function setIsExternal(bool $v): self { $this->isExternal = $v; return $this; }

    public function getTimezone(): ?string { return $this->timezone; }
    public function setTimezone(?string $tz): self { $this->timezone = $tz; return $this; }

    public function isRunning(): bool
    {
        return $this->endsAt === null;
    }
}
