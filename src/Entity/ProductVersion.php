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
use App\Entity\Enum\ProductVersionStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ProductVersionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A released version of a {@see Product} (e.g. "2.4.0"), carrying its release
 * date, notes and lifecycle status. Created through the product's release
 * endpoint (so {@see self::$isLatest} and {@see Product::$latestVersion} stay
 * consistent — see {@see \App\Service\Catalog\ProductCatalogService}); notes
 * and status may be edited afterwards.
 *
 * Customers are pinned to a specific version via {@see CustomerProduct}, which
 * is what later lets a successor version be offered/acquired.
 */
#[ORM\Entity(repositoryClass: ProductVersionRepository::class)]
#[ORM\Table(name: 'product_versions')]
#[ORM\UniqueConstraint(name: 'product_version_uniq', columns: ['product_id', 'version'])]
#[ORM\Index(name: 'product_version_product_idx', columns: ['product_id'])]
#[ORM\Index(name: 'product_version_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ProductVersion',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        // Creation goes through POST /v1/products/{id}/release so latest-version
        // bookkeeping stays consistent; notes/status are editable here.
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'product' => 'exact',
    'status' => 'exact',
    'version' => 'partial',
])]
#[ApiFilter(OrderFilter::class, properties: ['releaseDate', 'version', 'createdAt'])]
class ProductVersion
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne(inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 60)]
    private string $version;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $releaseDate = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $releaseNotes = null;

    #[ORM\Column(length: 12, enumType: ProductVersionStatus::class, options: ['default' => 'current'])]
    private ProductVersionStatus $status = ProductVersionStatus::Current;

    /** Maintained by ProductCatalogService — exactly one latest per product. */
    #[ApiProperty(writable: false)]
    #[ORM\Column(options: ['default' => false])]
    private bool $isLatest = false;

    public function getProduct(): Product { return $this->product; }
    public function setProduct(Product $p): self
    {
        $this->product = $p;
        $this->setWorkspace($p->getWorkspace());
        return $this;
    }

    public function getVersion(): string { return $this->version; }
    public function setVersion(string $v): self { $this->version = trim($v); return $this; }

    public function getReleaseDate(): ?\DateTimeImmutable { return $this->releaseDate; }
    public function setReleaseDate(?\DateTimeImmutable $d): self { $this->releaseDate = $d; return $this; }

    public function getReleaseNotes(): ?string { return $this->releaseNotes; }
    public function setReleaseNotes(?string $n): self { $this->releaseNotes = $n; return $this; }

    public function getStatus(): ProductVersionStatus { return $this->status; }
    public function setStatus(ProductVersionStatus $s): self { $this->status = $s; return $this; }

    public function isLatest(): bool { return $this->isLatest; }
    public function setIsLatest(bool $v): self { $this->isLatest = $v; return $this; }
}
