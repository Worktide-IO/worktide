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
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\TaskScheduleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Every X, create a Task in project Y" — recurring task definition.
 *
 * MVP: stores a cron expression (`0 9 * * 1` = Mondays 9am) plus a Task
 * template inline (title/description/priority/estimatedMinutes). When
 * `app:tasks:run-schedules` fires and `nextRunAt <= now`, the schedule
 * spawns a Task in `project` with these defaults and re-computes
 * `nextRunAt`.
 *
 * Storing a TaskTemplate FK would be cleaner (reusable templates) but
 * "every X create THIS specific task" is the 90% use case — inline keeps
 * the data model flat and the cron worker self-contained.
 */
#[ORM\Entity(repositoryClass: TaskScheduleRepository::class)]
#[ORM\Table(name: 'task_schedules')]
#[ORM\Index(name: 'task_schedule_next_run_idx', columns: ['next_run_at'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'TaskSchedule',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getProject())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getProject())"),
        new Delete(security: "is_granted('EDIT', object.getProject())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'project' => 'exact',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isEnabled'])]
#[ApiFilter(DateFilter::class, properties: ['nextRunAt'])]
#[ApiFilter(OrderFilter::class, properties: ['nextRunAt', 'name'])]
class TaskSchedule
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 120)]
    private string $name;

    /** Standard 5-field cron expression: `m h dom mon dow` */
    #[ORM\Column(length: 100)]
    private string $cronExpression = '0 9 * * 1'; // Mondays 9am

    #[ORM\Column(length: 64)]
    private string $timezone = 'Europe/Berlin';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nextRunAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column]
    private bool $isEnabled = true;

    /* ----- inline Task template ----- */

    #[ORM\Column(length: 200)]
    private string $taskTitle;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $taskDescription = null;

    #[ORM\Column(length: 12)]
    private string $taskPriority = 'normal';

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $taskEstimatedMinutes = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $taskAssignee = null;

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $p): self { $this->project = $p; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getCronExpression(): string { return $this->cronExpression; }
    public function setCronExpression(string $expr): self { $this->cronExpression = $expr; return $this; }

    public function getTimezone(): string { return $this->timezone; }
    public function setTimezone(string $tz): self { $this->timezone = $tz; return $this; }

    public function getNextRunAt(): ?\DateTimeImmutable { return $this->nextRunAt; }
    public function setNextRunAt(?\DateTimeImmutable $at): self { $this->nextRunAt = $at; return $this; }

    public function getLastRunAt(): ?\DateTimeImmutable { return $this->lastRunAt; }
    public function setLastRunAt(?\DateTimeImmutable $at): self { $this->lastRunAt = $at; return $this; }

    public function isEnabled(): bool { return $this->isEnabled; }
    public function setIsEnabled(bool $v): self { $this->isEnabled = $v; return $this; }

    public function getTaskTitle(): string { return $this->taskTitle; }
    public function setTaskTitle(string $t): self { $this->taskTitle = $t; return $this; }

    public function getTaskDescription(): ?string { return $this->taskDescription; }
    public function setTaskDescription(?string $d): self { $this->taskDescription = $d; return $this; }

    public function getTaskPriority(): string { return $this->taskPriority; }
    public function setTaskPriority(string $p): self { $this->taskPriority = $p; return $this; }

    public function getTaskEstimatedMinutes(): ?int { return $this->taskEstimatedMinutes; }
    public function setTaskEstimatedMinutes(?int $m): self { $this->taskEstimatedMinutes = $m; return $this; }

    public function getTaskAssignee(): ?User { return $this->taskAssignee; }
    public function setTaskAssignee(?User $u): self { $this->taskAssignee = $u; return $this; }
}
