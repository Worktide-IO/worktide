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
use App\Entity\Enum\CustomerProductStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\CustomerProductRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Assigns a {@see Product} to a {@see Customer}, pinned to the
 * {@see ProductVersion} the customer currently has. The version pin is what
 * lets a successor version be offered/acquired later (upgrade = point at the
 * next version), and what drives update outreach ("who runs a version older
 * than the latest?"). Services (versionless products) carry no version.
 */
#[ORM\Entity(repositoryClass: CustomerProductRepository::class)]
#[ORM\Table(name: 'customer_products')]
#[ORM\UniqueConstraint(name: 'customer_product_uniq', columns: ['customer_id', 'product_id'])]
#[ORM\Index(name: 'customer_product_customer_idx', columns: ['customer_id'])]
#[ORM\Index(name: 'customer_product_product_idx', columns: ['product_id'])]
#[ORM\Index(name: 'customer_product_version_idx', columns: ['version_id'])]
#[ORM\Index(name: 'customer_product_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'CustomerProduct',
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
    'customer' => 'exact',
    'product' => 'exact',
    'productVersion' => 'exact',
    'status' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['acquiredAt', 'createdAt'])]
class CustomerProduct implements HardDeleteOnly
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    /** The version the customer currently has — required for versioned products. */
    #[ORM\ManyToOne(targetEntity: ProductVersion::class)]
    #[ORM\JoinColumn(name: 'version_id', nullable: true, onDelete: 'RESTRICT')]
    private ?ProductVersion $productVersion = null;

    /** Optional system the product runs on. */
    #[ORM\ManyToOne(targetEntity: CustomerSystem::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CustomerSystem $system = null;

    #[ORM\Column(length: 12, enumType: CustomerProductStatus::class, options: ['default' => 'active'])]
    private CustomerProductStatus $status = CustomerProductStatus::Active;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $acquiredAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function getCustomer(): Customer { return $this->customer; }
    public function setCustomer(Customer $c): self
    {
        $this->customer = $c;
        $this->setWorkspace($c->getWorkspace());
        return $this;
    }

    public function getProduct(): Product { return $this->product; }
    public function setProduct(Product $p): self { $this->product = $p; return $this; }

    public function getProductVersion(): ?ProductVersion { return $this->productVersion; }
    public function setProductVersion(?ProductVersion $v): self { $this->productVersion = $v; return $this; }

    public function getSystem(): ?CustomerSystem { return $this->system; }
    public function setSystem(?CustomerSystem $s): self { $this->system = $s; return $this; }

    public function getStatus(): CustomerProductStatus { return $this->status; }
    public function setStatus(CustomerProductStatus $s): self { $this->status = $s; return $this; }

    public function getAcquiredAt(): ?\DateTimeImmutable { return $this->acquiredAt; }
    public function setAcquiredAt(?\DateTimeImmutable $d): self { $this->acquiredAt = $d; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }

    /**
     * A versioned product must be pinned to one of its own versions; a service
     * must not carry a version. Runs on every API write.
     */
    #[Assert\Callback]
    public function validateVersion(ExecutionContextInterface $context): void
    {
        // @phpstan-ignore-next-line — relations are set by the time validation runs
        if (!isset($this->product)) {
            return;
        }

        if ($this->product->isVersioned()) {
            if ($this->productVersion === null) {
                $context->buildViolation('A version is required for a versioned product.')
                    ->atPath('productVersion')->addViolation();
            } elseif ($this->productVersion->getProduct() !== $this->product) {
                $context->buildViolation('The version does not belong to the selected product.')
                    ->atPath('productVersion')->addViolation();
            }
        } elseif ($this->productVersion !== null) {
            $context->buildViolation('A service has no versions.')
                ->atPath('productVersion')->addViolation();
        }
    }
}
