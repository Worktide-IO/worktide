<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BackedEnumFilter;
use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use App\ApiPlatform\Filter\UuidExactFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Entity\Enum\FileTarget;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TaggableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\FileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Polymorphic file attachment. The "file" itself is metadata only — actual
 * bytes live on Flysystem-managed storage and are addressed through
 * FileVersion entries (one File can have N revisions; the latest is the
 * "current" version, kept on this row for fast lookups).
 *
 * Uploads happen through FileUploadController (multipart) and become version 1
 * of a new File row. Subsequent versions add new FileVersion rows without
 * mutating the File metadata.
 *
 * Cross-tenant safety: workspace-scoped + voter delegates to the target entity.
 */
#[ORM\Entity(repositoryClass: FileRepository::class)]
#[ORM\Table(name: 'files')]
#[ORM\Index(name: 'file_target_idx', columns: ['target', 'target_id'])]
#[ORM\Index(name: 'file_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'File',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object)"),
        // POST happens via the FileUploadController — multipart, not JSON.
        new Patch(security: "is_granted('EDIT', object)"),
        new Delete(security: "is_granted('DELETE', object)"),
    ],
    mercure: true,
)]
// SearchFilter can't match the enum `target` / uuid `targetId` scalar columns,
// so use BackedEnumFilter + UuidExactFilter (same as AIRecommendation).
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'mimeType' => 'partial',
    'name' => 'partial',
    'uploadedBy' => 'exact',
    'tags.id' => 'exact',
    'folder' => 'exact',
])]
#[ApiFilter(BackedEnumFilter::class, properties: ['target'])]
#[ApiFilter(UuidExactFilter::class, properties: ['targetId'])]
#[ApiFilter(BooleanFilter::class, properties: ['isExternal', 'isHiddenForConnectUsers'])]
#[ApiFilter(ExistsFilter::class, properties: ['folder'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
class File implements TaggableInterface
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;
    use TaggableTrait;

    #[ORM\Column(length: 16, enumType: FileTarget::class)]
    private FileTarget $target;

    #[ORM\Column(type: 'uuid')]
    private Uuid $targetId;

    /** Display name (defaults to original filename, editable). */
    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** MIME type of the current version (denormalised for fast filtering). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    /**
     * For files imported from external storage (Google Drive, OneDrive,
     * Dropbox, …). When set, no FileVersion bytes are stored — the file is
     * a pointer.
     */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $externalProvider = null;

    #[ORM\Column(length: 2000, nullable: true)]
    private ?string $externalUrl = null;

    #[ORM\Column]
    private bool $isExternal = false;

    #[ORM\Column]
    private bool $isHiddenForConnectUsers = false;

    /**
     * Reference to the most recent FileVersion. Maintained by FileUploadController.
     */
    #[ORM\ManyToOne(targetEntity: FileVersion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?FileVersion $currentVersion = null;

    /**
     * Folder this file lives in within its target's file tree; null = the
     * target's root. On folder delete the FK is nulled (SET NULL), but the
     * recursive FolderService soft-deletes contained files first.
     */
    #[ORM\ManyToOne(targetEntity: Folder::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Folder $folder = null;

    /** @var Collection<int, FileVersion> */
    #[ORM\OneToMany(targetEntity: FileVersion::class, mappedBy: 'file', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['versionNumber' => 'DESC'])]
    private Collection $versions;

    public function __construct()
    {
        $this->versions = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }

    public function getTarget(): FileTarget { return $this->target; }
    public function setTarget(FileTarget $target): self { $this->target = $target; return $this; }

    public function getTargetId(): Uuid { return $this->targetId; }
    public function setTargetId(Uuid $id): self { $this->targetId = $id; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getMimeType(): ?string { return $this->mimeType; }
    public function setMimeType(?string $type): self { $this->mimeType = $type; return $this; }

    public function getUploadedBy(): ?User { return $this->uploadedBy; }
    public function setUploadedBy(?User $user): self { $this->uploadedBy = $user; return $this; }

    public function getExternalProvider(): ?string { return $this->externalProvider; }
    public function setExternalProvider(?string $provider): self { $this->externalProvider = $provider; return $this; }

    public function getExternalUrl(): ?string { return $this->externalUrl; }
    public function setExternalUrl(?string $url): self { $this->externalUrl = $url; return $this; }

    public function isExternal(): bool { return $this->isExternal; }
    public function setIsExternal(bool $v): self { $this->isExternal = $v; return $this; }

    public function isHiddenForConnectUsers(): bool { return $this->isHiddenForConnectUsers; }
    public function setIsHiddenForConnectUsers(bool $v): self { $this->isHiddenForConnectUsers = $v; return $this; }

    public function getCurrentVersion(): ?FileVersion { return $this->currentVersion; }
    public function setCurrentVersion(?FileVersion $v): self { $this->currentVersion = $v; return $this; }

    public function getFolder(): ?Folder { return $this->folder; }
    public function setFolder(?Folder $folder): self { $this->folder = $folder; return $this; }

    /** @return Collection<int, FileVersion> */
    public function getVersions(): Collection { return $this->versions; }

    /** Convenience: byte size of the current version (or null for external/empty). */
    public function getSize(): ?int
    {
        return $this->currentVersion?->getSize();
    }
}
