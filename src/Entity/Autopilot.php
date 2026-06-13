<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
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
use App\Repository\AutopilotRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-Project alert rules — "warn the project lead when budget > 80%
 * consumed", "ping when a task is overdue by more than 3 days".
 *
 * One Autopilot per project (UNIQUE on project_id). Rules are stored as
 * JSON so adding new alert kinds doesn't require schema migrations; the
 * AutopilotEvaluator service knows how to interpret each kind.
 *
 * Available alert kinds (B8 MVP):
 *   - "budget_threshold"     { percent: 80 }
 *   - "overdue_tasks"        { gracePeriodDays: 0 }
 *   - "due_soon"             { withinDays: 3 }
 *
 * Each rule that triggers writes a DomainEvent named
 * "autopilot.{kind}.triggered" carrying the projectId + payload, plus
 * stamps lastTriggeredAt on the Autopilot. Consumers (notifications,
 * webhooks once B10 lands) subscribe to these events.
 */
#[ORM\Entity(repositoryClass: AutopilotRepository::class)]
#[ORM\Table(name: 'autopilots')]
#[ORM\UniqueConstraint(name: 'autopilot_project_unique', columns: ['project_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Autopilot',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getProject())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getProject())"),
        new Delete(security: "is_granted('EDIT', object.getProject())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'project' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['isEnabled'])]
class Autopilot
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    /**
     * @var array<int, array{kind: string, config?: array<string, mixed>, isEnabled?: bool}>
     */
    #[ORM\Column(type: 'json')]
    private array $rules = [];

    #[ORM\Column]
    private bool $isEnabled = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastTriggeredAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastEvaluatedAt = null;

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $project): self { $this->project = $project; return $this; }

    /** @return array<int, array{kind: string, config?: array<string, mixed>, isEnabled?: bool}> */
    public function getRules(): array { return $this->rules; }

    /** @param array<int, array{kind: string, config?: array<string, mixed>, isEnabled?: bool}> $rules */
    public function setRules(array $rules): self { $this->rules = $rules; return $this; }

    public function isEnabled(): bool { return $this->isEnabled; }
    public function setIsEnabled(bool $v): self { $this->isEnabled = $v; return $this; }

    public function getLastTriggeredAt(): ?\DateTimeImmutable { return $this->lastTriggeredAt; }
    public function markTriggered(): self { $this->lastTriggeredAt = new \DateTimeImmutable(); return $this; }

    public function getLastEvaluatedAt(): ?\DateTimeImmutable { return $this->lastEvaluatedAt; }
    public function markEvaluated(): self { $this->lastEvaluatedAt = new \DateTimeImmutable(); return $this; }
}
