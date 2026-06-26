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
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\DashboardRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named, workspace-shared dashboard — configurable widget canvas "beyond the
 * fixed report tabs" (ROADMAP Phase B Schicht 3).
 *
 * Distinct from the per-user quick layout in {@see UserPreferences::$dashboardLayout}:
 * a Dashboard is a first-class, named artifact visible to every member of its
 * workspace (scoping handled by {@see \App\ApiPlatform\Doctrine\WorkspaceScopeExtension}).
 * The creator ({@see AuditableTrait::$createdByUser}) owns it — owners and
 * workspace admins may edit/delete; any member may view.
 *
 * {@see $widgets} is an opaque JSON layout the SPA owns (react-grid-layout
 * shape), so the widget schema can evolve without a migration — same trade-off
 * as UserPreferences:
 *   [ { "key": "burndown", "x": 0, "y": 0, "w": 6, "h": 4, "config": {…} }, … ]
 * Unknown widget keys are ignored on render.
 */
#[ORM\Entity(repositoryClass: DashboardRepository::class)]
#[ORM\Table(name: 'dashboards')]
#[ORM\Index(name: 'dashboard_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Dashboard',
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
    'name' => 'partial',
])]
#[ApiFilter(OrderFilter::class, properties: ['position', 'name', 'createdAt'])]
class Dashboard
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $color = null;

    /**
     * SPA-owned widget layout (react-grid-layout shape). Opaque JSON.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $widgets = [];

    /** Ordering among a workspace's dashboards. */
    #[ORM\Column(type: 'float')]
    private float $position = 0;

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getIcon(): ?string { return $this->icon; }
    public function setIcon(?string $icon): self { $this->icon = $icon; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    /** @return list<array<string, mixed>> */
    public function getWidgets(): array { return $this->widgets; }

    /** @param list<array<string, mixed>> $widgets */
    public function setWidgets(array $widgets): self { $this->widgets = $widgets; return $this; }

    public function getPosition(): float { return $this->position; }
    public function setPosition(float $position): self { $this->position = $position; return $this; }
}
