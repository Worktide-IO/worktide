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
use App\Repository\ProjectTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Reusable blueprint for a Project. Captures the project's "defaults"
 * (name, description, color, projectType, billing flags) plus a reference
 * to a TaskBundle whose templates become the initial Tasks when this
 * project template is instantiated.
 *
 * `instantiate` (controller action) creates a new Project + copies bundle
 * templates into real Tasks, dueDates relative to the new project's start.
 */
#[ORM\Entity(repositoryClass: ProjectTemplateRepository::class)]
#[ORM\Table(name: 'project_templates')]
#[ORM\UniqueConstraint(name: 'project_template_workspace_name_unique', columns: ['workspace_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ProjectTemplate',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'name' => 'partial', 'projectType' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['isPublished', 'isArchived'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
class ProjectTemplate
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 16)]
    private string $color = '#6366f1';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProjectType $projectType = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TaskBundle $taskBundle = null;

    /** Defaults that flow into the instantiated Project. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $defaultBudgetMinutes = null;

    #[ORM\Column]
    private bool $defaultIsBillableByDefault = true;

    #[ORM\Column]
    private bool $defaultDeductNonBillableHours = false;

    #[ORM\Column]
    private bool $defaultIsMultiAssignmentAllowed = true;

    #[ORM\Column]
    private bool $defaultIsRetainer = false;

    #[ORM\Column]
    private bool $isPublished = true;

    #[ORM\Column]
    private bool $isArchived = false;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'project_template_tags')]
    private Collection $tags;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $color): self { $this->color = $color; return $this; }

    public function getProjectType(): ?ProjectType { return $this->projectType; }
    public function setProjectType(?ProjectType $type): self { $this->projectType = $type; return $this; }

    public function getTaskBundle(): ?TaskBundle { return $this->taskBundle; }
    public function setTaskBundle(?TaskBundle $bundle): self { $this->taskBundle = $bundle; return $this; }

    public function getDefaultBudgetMinutes(): ?int { return $this->defaultBudgetMinutes; }
    public function setDefaultBudgetMinutes(?int $minutes): self { $this->defaultBudgetMinutes = $minutes; return $this; }

    public function isDefaultIsBillableByDefault(): bool { return $this->defaultIsBillableByDefault; }
    public function setDefaultIsBillableByDefault(bool $v): self { $this->defaultIsBillableByDefault = $v; return $this; }

    public function isDefaultDeductNonBillableHours(): bool { return $this->defaultDeductNonBillableHours; }
    public function setDefaultDeductNonBillableHours(bool $v): self { $this->defaultDeductNonBillableHours = $v; return $this; }

    public function isDefaultIsMultiAssignmentAllowed(): bool { return $this->defaultIsMultiAssignmentAllowed; }
    public function setDefaultIsMultiAssignmentAllowed(bool $v): self { $this->defaultIsMultiAssignmentAllowed = $v; return $this; }

    public function isDefaultIsRetainer(): bool { return $this->defaultIsRetainer; }
    public function setDefaultIsRetainer(bool $v): self { $this->defaultIsRetainer = $v; return $this; }

    public function isPublished(): bool { return $this->isPublished; }
    public function setIsPublished(bool $v): self { $this->isPublished = $v; return $this; }

    public function isArchived(): bool { return $this->isArchived; }
    public function setIsArchived(bool $v): self { $this->isArchived = $v; return $this; }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection { return $this->tags; }

    public function addTag(Tag $tag): self
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
        return $this;
    }
}
