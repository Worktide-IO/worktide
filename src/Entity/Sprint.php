<?php

declare(strict_types=1);

namespace App\Entity;

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
use App\Entity\Enum\SprintState;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\SprintRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Sprint — a time-boxed iteration inside a Project that tasks are
 * committed to and a velocity is measured against. Distinct from
 * {@see ProjectVersion} (a ship target / release) and {@see ProjectMilestone}
 * (a checkpoint): a sprint has a start + end and an explicit lifecycle.
 *
 * A Task belongs to at most one Sprint (the `sprint` FK on Task); the
 * project's tasks with no sprint form the "Backlog". Deleting a sprint sends
 * its tasks back to the backlog (`onDelete: SET NULL`), it never deletes work.
 *
 * Velocity is computed from `Task.estimatedMinutes` — committed = sum over the
 * sprint's tasks, completed = the subset already closed (`closedOn` set). See
 * {@see \App\Controller\Api\ProjectReportsController::velocity()}.
 */
#[ORM\Entity(repositoryClass: SprintRepository::class)]
#[ORM\Table(name: 'sprints')]
#[ORM\UniqueConstraint(name: 'sprint_project_name_unique', columns: ['project_id', 'name'])]
#[ORM\Index(name: 'sprint_project_idx', columns: ['project_id'])]
#[ORM\Index(name: 'sprint_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'sprint_start_idx', columns: ['start_date'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Sprint',
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
    'state' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['startDate', 'endDate'])]
#[ApiFilter(OrderFilter::class, properties: ['startDate', 'position', 'name', 'createdAt'])]
class Sprint
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

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $goal = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(length: 12, enumType: SprintState::class, options: ['default' => 'planned'])]
    private SprintState $state = SprintState::Planned;

    /** Board ordering among the project's sprints. */
    #[ORM\Column(type: 'float', options: ['default' => 0])]
    private float $position = 0;

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $project): self { $this->project = $project; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getGoal(): ?string { return $this->goal; }
    public function setGoal(?string $goal): self { $this->goal = $goal; return $this; }

    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function setStartDate(?\DateTimeImmutable $d): self { $this->startDate = $d; return $this; }

    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setEndDate(?\DateTimeImmutable $d): self { $this->endDate = $d; return $this; }

    public function getState(): SprintState { return $this->state; }
    public function setState(SprintState $s): self { $this->state = $s; return $this; }

    public function getPosition(): float { return $this->position; }
    public function setPosition(float $p): self { $this->position = $p; return $this; }
}
