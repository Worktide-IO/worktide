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
use App\Repository\TypeOfWorkRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Trait\TranslatableTrait;

/**
 * Classification of time entries — "Development", "Project Management",
 * "Client Meeting", "Travel", etc.
 *
 * Beyond awork: we add isBillableByDefault here so creating a TimeEntry of
 * type "Travel" can default isBillable=false without the user setting it
 * each time. Reports can also break down billable vs non-billable hours
 * by type-of-work.
 */
#[ORM\Entity(repositoryClass: TypeOfWorkRepository::class)]
#[ORM\Table(name: 'types_of_work')]
#[ORM\UniqueConstraint(name: 'type_of_work_workspace_name_unique', columns: ['workspace_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'TypeOfWork',
    operations: [
        new GetCollection(uriTemplate: '/types_of_work', security: "is_granted('ROLE_USER')"),
        new Get(uriTemplate: '/types_of_work/{id}', security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(uriTemplate: '/types_of_work', security: "is_granted('ROLE_USER')"),
        new Patch(uriTemplate: '/types_of_work/{id}', security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(uriTemplate: '/types_of_work/{id}', security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'name' => 'partial'])]
#[ApiFilter(BooleanFilter::class, properties: ['isArchived', 'isBillableByDefault'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
class TypeOfWork implements TranslatableInterface
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

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $color = null;

    #[ORM\Column]
    private bool $isBillableByDefault = true;

    #[ORM\Column]
    private bool $isArchived = false;

    #[ORM\Column]
    private int $position = 0;

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $desc): self { $this->description = $desc; return $this; }

    public function getIcon(): ?string { return $this->icon; }
    public function setIcon(?string $icon): self { $this->icon = $icon; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function isBillableByDefault(): bool { return $this->isBillableByDefault; }
    public function setIsBillableByDefault(bool $v): self { $this->isBillableByDefault = $v; return $this; }

    public function isArchived(): bool { return $this->isArchived; }
    public function setIsArchived(bool $v): self { $this->isArchived = $v; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): self { $this->position = $p; return $this; }
    /**
     * @return list<string>
     */
    public static function translatableFields(): array
    {
        return ['name', 'description'];
    }

}
