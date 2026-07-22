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
use App\Entity\Enum\ProductShareStatus;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\ProductShareRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Represents a cross-workspace proposal to share a Product (or Service) with
 * another workspace. The receiving workspace can accept (copy into their
 * portfolio) or reject. Once accepted, the product is available for the
 * target workspace's customers.
 */
#[ORM\Entity(repositoryClass: ProductShareRepository::class)]
#[ORM\Table(name: 'product_shares')]
#[ORM\UniqueConstraint(name: 'product_share_uniq', columns: ['product_id', 'source_workspace_id', 'target_workspace_id'])]
#[ORM\Index(name: 'product_share_target_idx', columns: ['target_workspace_id', 'status'])]
#[ApiResource(
    shortName: 'ProductShare',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getSourceWorkspace()) or is_granted('VIEW', object.getTargetWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getTargetWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getSourceWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'sourceWorkspace' => 'exact',
    'targetWorkspace' => 'exact',
    'status' => 'exact',
    'product' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
class ProductShare
{
    use EntityIdTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $sourceWorkspace;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $targetWorkspace;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 16, enumType: ProductShareStatus::class)]
    private ProductShareStatus $status = ProductShareStatus::Proposed;

    /** Optional message from the proposer. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    /** The product copy in the target workspace after acceptance. */
    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $sharedCopy = null;

    public function getSourceWorkspace(): Workspace { return $this->sourceWorkspace; }
    public function setSourceWorkspace(Workspace $ws): self { $this->sourceWorkspace = $ws; return $this; }

    public function getTargetWorkspace(): Workspace { return $this->targetWorkspace; }
    public function setTargetWorkspace(Workspace $ws): self { $this->targetWorkspace = $ws; return $this; }

    public function getProduct(): Product { return $this->product; }
    public function setProduct(Product $p): self { $this->product = $p; return $this; }

    public function getStatus(): ProductShareStatus { return $this->status; }
    public function setStatus(ProductShareStatus $s): self { $this->status = $s; return $this; }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $m): self { $this->message = $m; return $this; }

    public function getSharedCopy(): ?Product { return $this->sharedCopy; }
    public function setSharedCopy(?Product $p): self { $this->sharedCopy = $p; return $this; }
}
