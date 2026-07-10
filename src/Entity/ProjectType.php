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
use App\Entity\Trait\ExternalReferenceTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ProjectTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Trait\TranslatableTrait;

/**
 * Categorisation of projects ("Client work", "Internal", "Retainer",
 * "Research", …). Workspace-scoped lookup with icon + color so the UI
 * can render project chips.
 *
 * Mirrors awork's `Project Types` (7 ops).
 */
#[ORM\Entity(repositoryClass: ProjectTypeRepository::class)]
#[ORM\Table(name: 'project_types')]
#[ORM\UniqueConstraint(name: 'project_type_workspace_name_unique', columns: ['workspace_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ProjectType',
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
#[ApiFilter(OrderFilter::class, properties: ['name', 'position', 'createdAt'])]
class ProjectType implements TranslatableInterface
{
    use TranslatableTrait;
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;
    use ExternalReferenceTrait;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $isArchived = false;

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getIcon(): ?string { return $this->icon; }
    public function setIcon(?string $icon): self { $this->icon = $icon; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    public function isArchived(): bool { return $this->isArchived; }
    public function setIsArchived(bool $value): self { $this->isArchived = $value; return $this; }
    /**
     * @return list<string>
     */
    public static function translatableFields(): array
    {
        return ['name', 'description'];
    }

}
