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
use App\Entity\Enum\CustomFieldTarget;
use App\Entity\Enum\CustomFieldType;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\CustomFieldDefinitionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Workspace-scoped definition of a user-defined field that can be attached to
 * Project / Task / TimeEntry (extensible via CustomFieldTarget enum).
 *
 * `target` controls which entity types may carry a value of this field.
 * `key` is the stable machine identifier used in API payloads and integrations.
 */
#[ORM\Entity(repositoryClass: CustomFieldDefinitionRepository::class)]
#[ORM\Table(name: 'custom_field_definitions')]
#[ORM\UniqueConstraint(name: 'custom_field_workspace_target_key_unique', columns: ['workspace_id', 'target', 'field_key'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'CustomFieldDefinition',
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'target' => 'exact',
    'type' => 'exact',
    'key' => 'exact',
    'label' => 'partial',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isRequired', 'isArchived'])]
#[ApiFilter(OrderFilter::class, properties: ['position', 'label', 'createdAt'])]
class CustomFieldDefinition
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 24, enumType: CustomFieldTarget::class)]
    private CustomFieldTarget $target;

    #[ORM\Column(length: 16, enumType: CustomFieldType::class)]
    private CustomFieldType $type;

    #[ORM\Column(name: 'field_key', length: 60)]
    private string $key;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * For Select / MultiSelect: list of allowed option labels.
     * Other types ignore this.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $options = [];

    #[ORM\Column]
    private bool $isRequired = false;

    #[ORM\Column]
    private bool $isArchived = false;

    #[ORM\Column]
    private int $position = 0;

    public function getTarget(): CustomFieldTarget
    {
        return $this->target;
    }

    public function setTarget(CustomFieldTarget $target): self
    {
        $this->target = $target;
        return $this;
    }

    public function getType(): CustomFieldType
    {
        return $this->type;
    }

    public function setType(CustomFieldType $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = $key;
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /** @return list<string> */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @param list<string> $options */
    public function setOptions(array $options): self
    {
        $this->options = array_values($options);
        return $this;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function setIsRequired(bool $value): self
    {
        $this->isRequired = $value;
        return $this;
    }

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function setIsArchived(bool $value): self
    {
        $this->isArchived = $value;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;
        return $this;
    }
}
