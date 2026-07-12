<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiPlatform\Filter\UuidExactFilter;
use App\Entity\Enum\ProductStatus;
use App\Entity\Enum\ProductType;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TaggableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Trait\TranslatableTrait;

/**
 * An item in the agency's own catalogue of offerings — either a versioned
 * `product` (carries {@see ProductVersion} releases) or a versionless
 * `service`. Catalogued so offerings can be assigned to customers
 * ({@see CustomerProduct}) and later drive update/upgrade outreach.
 *
 * {@see self::$slug} is the stable per-workspace key; {@see self::$latestVersion}
 * is maintained by {@see \App\Service\Catalog\ProductCatalogService} as new
 * versions ship, so "who is behind the latest version?" needs no scan.
 */
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
#[ORM\UniqueConstraint(name: 'product_ws_slug_uniq', columns: ['workspace_id', 'slug'])]
#[ORM\Index(name: 'product_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'product_type_idx', columns: ['type'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Product',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(UuidExactFilter::class, properties: ['id'])]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'slug' => 'exact',
    'type' => 'exact',
    'status' => 'exact',
    'name' => 'partial',
    'tags.id' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
class Product implements TranslatableInterface, TaggableInterface
{
    use TranslatableTrait;
    use TaggableTrait;
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    private string $name;

    /** Stable lowercase key, unique per workspace. */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9_-]*$/', message: 'Slug must be lowercase letters, digits, dash or underscore.')]
    private string $slug;

    #[ORM\Column(length: 12, enumType: ProductType::class)]
    private ProductType $type = ProductType::Product;

    #[ORM\Column(length: 12, enumType: ProductStatus::class, options: ['default' => 'active'])]
    private ProductStatus $status = ProductStatus::Active;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $category = null;

    /** The newest released version — maintained by ProductCatalogService. */
    #[ApiProperty(writable: false)]
    #[ORM\ManyToOne(targetEntity: ProductVersion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProductVersion $latestVersion = null;

    /** @var Collection<int, ProductVersion> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductVersion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['releaseDate' => 'DESC'])]
    private Collection $versions;

    public function __construct()
    {
        $this->versions = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }

    public function getName(): string { return $this->name; }
    public function setName(string $n): self { $this->name = $n; return $this; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $s): self { $this->slug = strtolower(trim($s)); return $this; }

    public function getType(): ProductType { return $this->type; }
    public function setType(ProductType $t): self { $this->type = $t; return $this; }

    public function getStatus(): ProductStatus { return $this->status; }
    public function setStatus(ProductStatus $s): self { $this->status = $s; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $c): self { $this->category = $c; return $this; }

    public function getLatestVersion(): ?ProductVersion { return $this->latestVersion; }
    public function setLatestVersion(?ProductVersion $v): self { $this->latestVersion = $v; return $this; }

    /** @return Collection<int, ProductVersion> */
    public function getVersions(): Collection { return $this->versions; }

    public function addVersion(ProductVersion $v): self
    {
        if (!$this->versions->contains($v)) {
            $this->versions->add($v);
            $v->setProduct($this);
        }
        return $this;
    }

    public function isVersioned(): bool { return $this->type->isVersioned(); }
    /**
     * @return list<string>
     */
    public static function translatableFields(): array
    {
        return ['name', 'description'];
    }

}
