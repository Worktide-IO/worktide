<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
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
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ServiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A billable service the agency offers — the catalogue head. Its price is not
 * stored here but on its {@see ServiceVersion} fassungen: a new version is
 * released whenever the net price or billing cycle changes, so the price
 * history stays intact and existing {@see ServiceAssignment}s keep pointing at
 * the version they were signed on.
 *
 * {@see self::$currentVersion} is the fassung new assignments default to,
 * maintained by {@see \App\Service\Catalog\ServiceCatalogService} as versions
 * ship (analogous to {@see Product::$latestVersion}).
 *
 * Distinct from the {@see Product} catalogue (software-version tracking, no
 * pricing) — this one carries billing/MRR semantics.
 */
#[ORM\Entity(repositoryClass: ServiceRepository::class)]
#[ORM\Table(name: 'services')]
#[ORM\Index(name: 'service_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'service_name_idx', columns: ['workspace_id', 'name'])]
#[ORM\Index(name: 'service_parent_idx', columns: ['parent_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Service',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(UuidExactFilter::class, properties: ['id'])]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'category' => 'exact',
    'active' => 'exact',
    'name' => 'partial',
])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'position', 'createdAt'])]
#[ApiFilter(ExistsFilter::class, properties: ['parent'])]
#[ApiFilter(SearchFilter::class, properties: ['parent' => 'exact'])]
class Service
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $category = null;

    /** Parent node for tree-like categorisation. */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Service $parent = null;

    /** @var Collection<int, Service> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    #[ORM\OrderBy(['position' => 'ASC', 'name' => 'ASC'])]
    private Collection $children;

    /** Sort order among siblings. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** The fassung new assignments default to — maintained by ServiceCatalogService. */
    #[ApiProperty(writable: false)]
    #[ORM\ManyToOne(targetEntity: ServiceVersion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ServiceVersion $currentVersion = null;

    /** @var Collection<int, ServiceVersion> */
    #[ORM\OneToMany(mappedBy: 'service', targetEntity: ServiceVersion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['versionNo' => 'DESC'])]
    private Collection $versions;

    public function __construct()
    {
        $this->versions = new ArrayCollection();
        $this->children = new ArrayCollection();
    }

    public function getName(): string { return $this->name; }
    public function setName(string $n): self { $this->name = $n; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $c): self { $this->category = $c; return $this; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $v): self { $this->active = $v; return $this; }

    public function getCurrentVersion(): ?ServiceVersion { return $this->currentVersion; }
    public function setCurrentVersion(?ServiceVersion $v): self { $this->currentVersion = $v; return $this; }

    /** @return Collection<int, ServiceVersion> */
    public function getVersions(): Collection { return $this->versions; }

    public function addVersion(ServiceVersion $v): self
    {
        if (!$this->versions->contains($v)) {
            $this->versions->add($v);
            $v->setService($this);
        }
        return $this;
    }

    public function getParent(): ?self { return $this->parent; }
    public function setParent(?self $p): self { $this->parent = $p; return $this; }

    /** @return Collection<int, Service> */
    public function getChildren(): Collection { return $this->children; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): self { $this->position = $p; return $this; }

    public function isRoot(): bool { return $this->parent === null; }
}
