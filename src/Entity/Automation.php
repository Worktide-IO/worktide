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
use App\Entity\Enum\AutomationTriggerType;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\AutomationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * "When trigger X fires, run actions Y" — the building block of a Workflow.
 *
 * triggerConfig is a JSON object whose shape depends on triggerType:
 *   task.status_changed   → { toStatusId?: uuid, fromStatusId?: uuid }
 *   task.assignee_changed → { newAssigneeId?: uuid }
 *   task.created          → (none, fires for every new task in the workspace)
 *   etc.
 *
 * AutomationDispatcher (the doctrine listener) reads triggerConfig to filter
 * which raw domain events should actually trigger this automation.
 */
#[ORM\Entity(repositoryClass: AutomationRepository::class)]
#[ORM\Table(name: 'automations')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Automation',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkflow().getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkflow().getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkflow().getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'workflow' => 'exact',
    'triggerType' => 'exact',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isEnabled'])]
#[ApiFilter(OrderFilter::class, properties: ['position', 'name'])]
class Automation
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne(inversedBy: 'automations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workflow $workflow;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 32, enumType: AutomationTriggerType::class)]
    private AutomationTriggerType $triggerType;

    /** @var array<string, mixed> trigger-type-specific filter config */
    #[ORM\Column(type: 'json')]
    private array $triggerConfig = [];

    #[ORM\Column]
    private bool $isEnabled = true;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, AutomationAction> */
    #[ORM\OneToMany(targetEntity: AutomationAction::class, mappedBy: 'automation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $actions;

    public function __construct()
    {
        $this->actions = new ArrayCollection();
    }

    public function getWorkflow(): Workflow { return $this->workflow; }
    public function setWorkflow(Workflow $workflow): self { $this->workflow = $workflow; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getTriggerType(): AutomationTriggerType { return $this->triggerType; }
    public function setTriggerType(AutomationTriggerType $type): self { $this->triggerType = $type; return $this; }

    /** @return array<string, mixed> */
    public function getTriggerConfig(): array { return $this->triggerConfig; }

    /** @param array<string, mixed> $config */
    public function setTriggerConfig(array $config): self { $this->triggerConfig = $config; return $this; }

    public function isEnabled(): bool { return $this->isEnabled; }
    public function setIsEnabled(bool $v): self { $this->isEnabled = $v; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): self { $this->position = $p; return $this; }

    /** @return Collection<int, AutomationAction> */
    public function getActions(): Collection { return $this->actions; }
}
