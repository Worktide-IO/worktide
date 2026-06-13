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
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\TaskBundleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Reusable group of TaskTemplates. Bundles are the unit that gets "applied"
 * to a project (via apply-bundle) and the building block ProjectTemplate
 * uses to define what tasks come with a new project.
 *
 * Mirrors awork's TaskBundle category — they call this "the 29-op heavy" but
 * 80% of those ops are CRUD on the nested TaskTemplate; our flat /v1/task_templates
 * collection covers it with workspace-aware filters.
 */
#[ORM\Entity(repositoryClass: TaskBundleRepository::class)]
#[ORM\Table(name: 'task_bundles')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'TaskBundle',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'name' => 'partial'])]
#[ApiFilter(BooleanFilter::class, properties: ['isArchived'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
class TaskBundle
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

    #[ORM\Column(length: 16)]
    private string $color = '#6366f1';

    #[ORM\Column]
    private bool $isArchived = false;

    /** @var Collection<int, TaskTemplate> */
    #[ORM\OneToMany(targetEntity: TaskTemplate::class, mappedBy: 'bundle', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $taskTemplates;

    public function __construct()
    {
        $this->taskTemplates = new ArrayCollection();
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $color): self { $this->color = $color; return $this; }

    public function isArchived(): bool { return $this->isArchived; }
    public function setIsArchived(bool $value): self { $this->isArchived = $value; return $this; }

    /** @return Collection<int, TaskTemplate> */
    public function getTaskTemplates(): Collection { return $this->taskTemplates; }
}
