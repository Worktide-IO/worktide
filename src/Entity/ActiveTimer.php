<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ActiveTimerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Currently-running stopwatch for one user. At most one row per user (UNIQUE
 * on user_id) — starting a new timer auto-stops the previous one and turns it
 * into a finalized TimeEntry.
 *
 * This is a deliberately separate entity from TimeEntry so:
 *   - TimeEntries stay immutable historical records,
 *   - the running stopwatch doesn't pollute reports with a zero-or-partial
 *     duration row,
 *   - the polling endpoint to render "Sven is tracking X" only hits one row.
 *
 * Read-only via the API; clients drive transitions through the dedicated
 * /v1/timers/start and /v1/timers/stop endpoints.
 */
#[ORM\Entity(repositoryClass: ActiveTimerRepository::class)]
#[ORM\Table(name: 'active_timers')]
#[ORM\UniqueConstraint(name: 'active_timer_user_unique', columns: ['user_id'])]
#[ORM\Index(name: 'active_timer_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ActiveTimer',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('ROLE_USER')"),
    ],
)]
class ActiveTimer
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Task $task = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TypeOfWork $typeOfWork = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $isBillable = true;

    public function getUser(): User { return $this->user; }
    public function setUser(User $u): self { $this->user = $u; return $this; }

    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(\DateTimeImmutable $when): self { $this->startedAt = $when; return $this; }

    public function getTask(): ?Task { return $this->task; }
    public function setTask(?Task $task): self { $this->task = $task; return $this; }

    public function getProject(): ?Project { return $this->project; }
    public function setProject(?Project $p): self { $this->project = $p; return $this; }

    public function getTypeOfWork(): ?TypeOfWork { return $this->typeOfWork; }
    public function setTypeOfWork(?TypeOfWork $t): self { $this->typeOfWork = $t; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $desc): self { $this->description = $desc; return $this; }

    public function isBillable(): bool { return $this->isBillable; }
    public function setIsBillable(bool $v): self { $this->isBillable = $v; return $this; }

    public function elapsedSeconds(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        return max(0, $now->getTimestamp() - $this->startedAt->getTimestamp());
    }
}
