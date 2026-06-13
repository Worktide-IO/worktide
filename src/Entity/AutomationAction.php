<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\AutomationActionType;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Repository\AutomationActionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One step the Automation runs when triggered. Multiple Actions per
 * Automation run in `position` order; if one fails the remainder still
 * execute (best-effort). config shape is type-specific:
 *
 *   task.set_status      { statusId: uuid }
 *   task.set_priority    { priority: low|normal|high|urgent }
 *   task.add_tag         { tagId: uuid }
 *   task.assign_user     { userId: uuid }
 *   task.post_comment    { content: string }
 *   task.close           — no config
 *
 * Action targets the Task that triggered the parent Automation (we don't
 * cross-target yet; in awork actions can fan out — that's a future block).
 */
#[ORM\Entity(repositoryClass: AutomationActionRepository::class)]
#[ORM\Table(name: 'automation_actions')]
#[ORM\Index(name: 'automation_action_automation_idx', columns: ['automation_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'AutomationAction',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getAutomation().getWorkflow().getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getAutomation().getWorkflow().getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getAutomation().getWorkflow().getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['automation' => 'exact', 'type' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['position'])]
class AutomationAction
{
    use EntityIdTrait;
    use TimestampableTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne(inversedBy: 'actions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Automation $automation;

    #[ORM\Column(length: 32, enumType: AutomationActionType::class)]
    private AutomationActionType $type;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $config = [];

    #[ORM\Column]
    private int $position = 0;

    public function getAutomation(): Automation { return $this->automation; }
    public function setAutomation(Automation $a): self { $this->automation = $a; return $this; }

    public function getType(): AutomationActionType { return $this->type; }
    public function setType(AutomationActionType $type): self { $this->type = $type; return $this; }

    /** @return array<string, mixed> */
    public function getConfig(): array { return $this->config; }

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): self { $this->config = $config; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): self { $this->position = $p; return $this; }
}
