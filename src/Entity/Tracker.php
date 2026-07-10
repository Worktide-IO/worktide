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
use App\Repository\TrackerRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Trait\TranslatableTrait;

/**
 * Issue tracker classification — Bug, Feature, Story, Support, Epic, …
 *
 * A Tracker is the "issue type" axis on top of TaskStatus (the lifecycle
 * axis) and TaskPriority (the urgency axis). It tells the SPA which
 * icon + color to show, lets reports group by issue type, and (in
 * Phase B Schicht-2) drives the workflow-per-tracker engine.
 *
 * Workspace-scoped + name-unique. Trackers are seeded per workspace
 * during onboarding (a fixture provides the four classic ones) and can
 * be edited / extended later from /workspace-settings/trackers.
 *
 * `isDefault=true` marks the tracker assigned to brand-new tasks when
 * the caller doesn't specify one. Exactly one default per workspace —
 * enforced by a Symfony validator (not a DB unique constraint, so a
 * brief "two defaults in transit" state during a re-assignment doesn't
 * deadlock the UI).
 *
 * `defaultStatus` lets a Bug start in a different status than a Story
 * if a workspace wants that. Nullable — if unset, the workspace's
 * isDefault TaskStatus wins.
 *
 * Hard-delete is allowed but blocked at the DB level (`onDelete: RESTRICT`
 * on Task.tracker) when any task still references it; the SPA should
 * offer "reassign all tasks of this tracker to X" first.
 */
#[ORM\Entity(repositoryClass: TrackerRepository::class)]
#[ORM\Table(name: 'trackers')]
#[ORM\UniqueConstraint(name: 'tracker_workspace_name_unique', columns: ['workspace_id', 'name'])]
#[ORM\Index(name: 'tracker_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Tracker',
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'name' => 'partial',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isDefault'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'position', 'createdAt'])]
class Tracker implements TranslatableInterface
{
    use TranslatableTrait;
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 60)]
    private string $name;

    /**
     * Lucide icon name (the SPA's icon library). Free-form so the
     * frontend can map it to its <Icon name="bug" /> component without
     * a server-side enum that has to ship every time an icon is added.
     * Examples: "bug", "sparkles", "book-open", "life-buoy", "milestone".
     */
    #[ORM\Column(length: 40)]
    private string $icon = 'circle';

    #[ORM\Column(length: 16)]
    private string $color = '#94a3b8';

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $isDefault = false;

    /**
     * Optional per-tracker initial status. If null, the workspace's
     * default TaskStatus is used at task-creation time. Nullable on
     * purpose — many workspaces will keep one shared "Todo" status for
     * all trackers.
     */
    #[ORM\ManyToOne(targetEntity: TaskStatus::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TaskStatus $defaultStatus = null;

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getIcon(): string { return $this->icon; }
    public function setIcon(string $icon): self { $this->icon = $icon; return $this; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $color): self { $this->color = $color; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): self { $this->position = $p; return $this; }

    public function isDefault(): bool { return $this->isDefault; }
    public function setIsDefault(bool $v): self { $this->isDefault = $v; return $this; }

    public function getDefaultStatus(): ?TaskStatus { return $this->defaultStatus; }
    public function setDefaultStatus(?TaskStatus $s): self { $this->defaultStatus = $s; return $this; }
    /**
     * @return list<string>
     */
    public static function translatableFields(): array
    {
        return ['name'];
    }

}
