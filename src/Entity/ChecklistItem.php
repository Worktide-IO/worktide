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
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ChecklistItemRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sub-todo on a Task. Lightweight by design — for proper sub-work items use
 * Task.parent (subtasks). Checklists are visual tick-boxes shown inside the
 * task detail panel.
 *
 * Going beyond awork: we track who checked the item off and when, so the
 * activity feed can attribute the tick to a user (awork's schema doesn't
 * expose checkedBy / checkedOn).
 */
#[ORM\Entity(repositoryClass: ChecklistItemRepository::class)]
#[ORM\Table(name: 'checklist_items')]
#[ORM\Index(name: 'checklist_item_task_idx', columns: ['task_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ChecklistItem',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object)"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object)"),
        new Delete(security: "is_granted('DELETE', object)"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'task' => 'exact',
    'name' => 'partial',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isDone'])]
#[ApiFilter(OrderFilter::class, properties: ['position', 'createdAt'])]
class ChecklistItem
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne(inversedBy: 'checklistItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\Column(length: 300)]
    private string $name;

    #[ORM\Column]
    private bool $isDone = false;

    #[ORM\Column(type: 'float')]
    private float $position = 0.0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $checkedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkedOn = null;

    public function getTask(): Task { return $this->task; }
    public function setTask(Task $task): self { $this->task = $task; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function isDone(): bool { return $this->isDone; }

    public function setIsDone(bool $done): self
    {
        if ($done && !$this->isDone) {
            $this->checkedOn = new \DateTimeImmutable();
        } elseif (!$done && $this->isDone) {
            $this->checkedOn = null;
            $this->checkedBy = null;
        }
        $this->isDone = $done;
        return $this;
    }

    public function setCheckedBy(?User $by): self
    {
        $this->checkedBy = $by;
        return $this;
    }

    public function getPosition(): float { return $this->position; }
    public function setPosition(float $p): self { $this->position = $p; return $this; }

    public function getCheckedBy(): ?User { return $this->checkedBy; }
    public function getCheckedOn(): ?\DateTimeImmutable { return $this->checkedOn; }
}
