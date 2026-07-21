<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\TranslatableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ProductFeatureRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A single feature / capability / highlight of a specific {@see ProductVersion},
 * intended for marketing-oriented product detail pages. Features are versioned
 * so the feature list evolves alongside the product releases.
 *
 * {@see self::$icon} accepts any short identifier the frontend can render
 * (Lucide icon name, single emoji, or null).
 */
#[ORM\Entity(repositoryClass: ProductFeatureRepository::class)]
#[ORM\Table(name: 'product_features')]
#[ORM\Index(name: 'feature_version_idx', columns: ['version_id'])]
#[ORM\Index(name: 'feature_position_idx', columns: ['position'])]
#[ApiResource(
    shortName: 'ProductFeature',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'version' => 'exact',
    'version.product' => 'exact',
    'workspace' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['position', 'name'])]
class ProductFeature implements TranslatableInterface
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use TranslatableTrait;

    #[ORM\ManyToOne(inversedBy: 'features')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProductVersion $version;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $position = 0;

    /** Emoji or Lucide icon name rendered by the frontend. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $icon = null;

    public function getVersion(): ProductVersion { return $this->version; }
    public function setVersion(ProductVersion $v): self
    {
        $this->version = $v;
        $this->setWorkspace($v->getWorkspace());
        return $this;
    }

    public function getName(): string { return $this->name; }
    public function setName(string $n): self { $this->name = $n; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): self { $this->position = $p; return $this; }

    public function getIcon(): ?string { return $this->icon; }
    public function setIcon(?string $i): self { $this->icon = $i; return $this; }

    /** @return list<string> */
    public static function translatableFields(): array
    {
        return ['name', 'description'];
    }
}
