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
use App\Repository\TaskViewRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persisted task-list filter the user keeps coming back to ("Open bugs",
 * "My tasks due this week", "Sprint backlog"). Captures the query as JSON
 * so the UI can re-apply it without rebuilding the filter chip-by-chip.
 *
 * isShared flips it from private to workspace-wide. We skip awork's
 * subscribe/unsubscribe sub-resource — a shared view is simply visible
 * to every workspace member; clients keep "their views" by filtering
 * the list with ?owner=<me>.
 */
#[ORM\Entity(repositoryClass: TaskViewRepository::class)]
#[ORM\Table(name: 'task_views')]
#[ORM\Index(name: 'task_view_owner_idx', columns: ['owner_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'TaskView',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_USER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'owner' => 'exact', 'name' => 'partial'])]
#[ApiFilter(BooleanFilter::class, properties: ['isShared'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
class TaskView
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $icon = null;

    /** @var array<string, mixed> arbitrary filter — same keys as /v1/tasks?<filter> */
    #[ORM\Column(type: 'json')]
    private array $filter = [];

    /** @var array<string, string> field name → 'asc'|'desc' */
    #[ORM\Column(type: 'json')]
    private array $sortOrder = [];

    #[ORM\Column]
    private bool $isShared = false;

    public function getOwner(): User { return $this->owner; }
    public function setOwner(User $owner): self { $this->owner = $owner; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function getIcon(): ?string { return $this->icon; }
    public function setIcon(?string $icon): self { $this->icon = $icon; return $this; }

    /** @return array<string, mixed> */
    public function getFilter(): array { return $this->filter; }

    /** @param array<string, mixed> $filter */
    public function setFilter(array $filter): self { $this->filter = $filter; return $this; }

    /** @return array<string, string> */
    public function getSortOrder(): array { return $this->sortOrder; }

    /** @param array<string, string> $sortOrder */
    public function setSortOrder(array $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }

    public function isShared(): bool { return $this->isShared; }
    public function setIsShared(bool $v): self { $this->isShared = $v; return $this; }
}
