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
use App\Repository\IndustryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Trait\TranslatableTrait;

/**
 * A workspace-managed industry/sector ("Branche") a {@see Customer} can be
 * assigned to. A controlled vocabulary — replacing the old free-text field —
 * so customers can be filtered and segmented reliably. New entries can be
 * added on the fly from the customer form's type-ahead.
 */
#[ORM\Entity(repositoryClass: IndustryRepository::class)]
#[ORM\Table(name: 'industries')]
#[ORM\UniqueConstraint(name: 'industry_ws_name_uniq', columns: ['workspace_id', 'name'])]
#[ORM\Index(name: 'industry_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Industry',
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
#[ApiFilter(OrderFilter::class, properties: ['position', 'name'])]
class Industry implements TranslatableInterface
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

    /** Sort order for the picker. */
    #[ORM\Column(type: 'float', options: ['default' => 0])]
    private float $position = 0.0;

    #[ORM\Column(options: ['default' => false])]
    private bool $isArchived = false;

    public function getName(): string { return $this->name; }
    public function setName(string $n): self { $this->name = trim($n); return $this; }

    public function getPosition(): float { return $this->position; }
    public function setPosition(float $p): self { $this->position = $p; return $this; }

    public function isArchived(): bool { return $this->isArchived; }
    public function setIsArchived(bool $v): self { $this->isArchived = $v; return $this; }
    /**
     * @return list<string>
     */
    public static function translatableFields(): array
    {
        return ['name'];
    }

}
