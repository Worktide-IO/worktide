<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\CustomFieldTarget;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\CustomFieldValueRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Value of a CustomFieldDefinition for a specific entity instance.
 *
 * Polymorphic by storing (target, target_id) rather than a per-type FK. Trade-off:
 * no DB-level referential integrity to the target row — clean-up is done by
 * SoftDelete listeners or maintenance jobs. We accept this for the flexibility of
 * adding new targets without schema migrations.
 *
 * The `value` column is JSON to support all CustomFieldType variants
 * (string / number / bool / date / array-for-multiselect / uuid-for-user).
 */
#[ORM\Entity(repositoryClass: CustomFieldValueRepository::class)]
#[ORM\Table(name: 'custom_field_values')]
#[ORM\UniqueConstraint(name: 'cfv_definition_target_unique', columns: ['definition_id', 'target_id'])]
#[ORM\Index(name: 'cfv_target_idx', columns: ['target', 'target_id'])]
#[ORM\Index(name: 'cfv_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'CustomFieldValue',
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
    'definition' => 'exact',
    'target' => 'exact',
    'targetId' => 'exact',
])]
class CustomFieldValue
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CustomFieldDefinition $definition;

    #[ORM\Column(length: 24, enumType: CustomFieldTarget::class)]
    private CustomFieldTarget $target;

    #[ORM\Column(type: 'uuid')]
    private Uuid $targetId;

    /** @var array{value: mixed} */
    #[ORM\Column(type: 'json')]
    private array $value = ['value' => null];

    public function getDefinition(): CustomFieldDefinition
    {
        return $this->definition;
    }

    public function setDefinition(CustomFieldDefinition $definition): self
    {
        $this->definition = $definition;
        return $this;
    }

    public function getTarget(): CustomFieldTarget
    {
        return $this->target;
    }

    public function setTarget(CustomFieldTarget $target): self
    {
        $this->target = $target;
        return $this;
    }

    public function getTargetId(): Uuid
    {
        return $this->targetId;
    }

    public function setTargetId(Uuid $id): self
    {
        $this->targetId = $id;
        return $this;
    }

    public function getValue(): mixed
    {
        return $this->value['value'] ?? null;
    }

    public function setValue(mixed $value): self
    {
        $this->value = ['value' => $value];
        return $this;
    }
}
