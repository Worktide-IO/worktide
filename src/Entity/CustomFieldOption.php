<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\CustomFieldOptionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One selectable option for a `select` / `multi_select` CustomFieldDefinition.
 *
 * Replaces the previous `CustomFieldDefinition.options[]` plain string array
 * so options keep a stable identity across rename + colour changes — matches
 * awork's `selectionOptions` sub-entity (id / value / color / order).
 *
 * Managed through API Platform on the definition: GET via the parent's
 * `options` relation, POST/PATCH/DELETE individual ones.
 */
#[ORM\Entity(repositoryClass: CustomFieldOptionRepository::class)]
#[ORM\Table(name: 'custom_field_options')]
#[ORM\Index(name: 'cfo_definition_idx', columns: ['definition_id'])]
#[ORM\HasLifecycleCallbacks]
class CustomFieldOption
{
    use EntityIdTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne(inversedBy: 'optionDefinitions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CustomFieldDefinition $definition;

    #[ORM\Column(length: 120)]
    private string $value;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $color = null;

    #[ORM\Column]
    private int $position = 0;

    public function getDefinition(): CustomFieldDefinition { return $this->definition; }
    public function setDefinition(CustomFieldDefinition $d): self { $this->definition = $d; return $this; }

    public function getValue(): string { return $this->value; }
    public function setValue(string $v): self { $this->value = $v; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $c): self { $this->color = $c; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): self { $this->position = $p; return $this; }
}
