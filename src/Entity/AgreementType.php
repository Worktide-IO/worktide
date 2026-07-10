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
use App\Repository\AgreementTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Trait\TranslatableTrait;

/**
 * A workspace-configurable kind of customer agreement — SLA, AV-Vertrag
 * (Auftragsverarbeitung / DPA), Geheimhaltungsvereinbarung (NDA), and any
 * further types the workspace defines.
 *
 * The {@see self::$slug} is the stable, human-friendly key clients address
 * agreements by (e.g. `GET /v1/customers/{id}/agreements/sla`), so it is
 * unique per workspace and immutable-by-convention once in use. {@see
 * self::$isMandatory} lets the overview flag missing required agreements.
 */
#[ORM\Entity(repositoryClass: AgreementTypeRepository::class)]
#[ORM\Table(name: 'agreement_types')]
#[ORM\UniqueConstraint(name: 'agreement_type_ws_slug_uniq', columns: ['workspace_id', 'slug'])]
#[ORM\Index(name: 'agreement_type_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'AgreementType',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'slug' => 'exact',
    'name' => 'partial',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isMandatory', 'isArchived'])]
#[ApiFilter(OrderFilter::class, properties: ['position', 'name'])]
class AgreementType implements TranslatableInterface
{
    use TranslatableTrait;
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $name;

    /** Stable lowercase key (`sla`, `av`, `nda`); unique per workspace. */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9_-]*$/', message: 'Slug must be lowercase letters, digits, dash or underscore.')]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Required agreement — the overview marks customers lacking it. */
    #[ORM\Column(options: ['default' => false])]
    private bool $isMandatory = false;

    /** Sort order of the overview columns. */
    #[ORM\Column(type: 'float', options: ['default' => 0])]
    private float $position = 0.0;

    #[ORM\Column(options: ['default' => false])]
    private bool $isArchived = false;

    public function getName(): string { return $this->name; }
    public function setName(string $n): self { $this->name = $n; return $this; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $s): self { $this->slug = strtolower(trim($s)); return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }

    public function isMandatory(): bool { return $this->isMandatory; }
    public function setIsMandatory(bool $v): self { $this->isMandatory = $v; return $this; }

    public function getPosition(): float { return $this->position; }
    public function setPosition(float $p): self { $this->position = $p; return $this; }

    public function isArchived(): bool { return $this->isArchived; }
    public function setIsArchived(bool $v): self { $this->isArchived = $v; return $this; }
    /**
     * @return list<string>
     */
    public static function translatableFields(): array
    {
        return ['name', 'description'];
    }

}
