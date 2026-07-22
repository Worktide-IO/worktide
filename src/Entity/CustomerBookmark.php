<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\BookmarkType;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TaggableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\CustomerBookmarkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One connection / login bookmark for a customer — browser URLs, SSH/SFTP/FTP
 * servers, RDP/VNC remotes, database connections. Credentials are encrypted
 * at rest via {@see \App\EventSubscriber\BookmarkCredentialsCipherListener}.
 */
#[ORM\Entity(repositoryClass: CustomerBookmarkRepository::class)]
#[ORM\Table(name: 'customer_bookmarks')]
#[ORM\Index(name: 'bookmark_customer_idx', columns: ['customer_id'])]
#[ORM\Index(name: 'bookmark_workspace_idx', columns: ['workspace_id', 'type'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'CustomerBookmark',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'customer' => 'exact',
    'system' => 'exact',
    'type' => 'exact',
    'host' => 'partial',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isEnabled', 'isShared'])]
class CustomerBookmark
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use VersionedTrait;
    use AuditableTrait;
    use WorkspaceScopedTrait;
    use TaggableTrait;

    /** Human-readable label, e.g. "TYPO3 Staging SSH". */
    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $label = '';

    /** Protocol type — controls which connectConfig fields are relevant. */
    #[ORM\Column(length: 8, enumType: BookmarkType::class)]
    #[Assert\NotBlank]
    private BookmarkType $type = BookmarkType::Web;

    /** Hostname or IP address; may be empty for plain-URL web bookmarks. */
    #[ORM\Column(length: 253)]
    private string $host = '';

    /** Port number; null means "use default for this type". */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $port = null;

    /**
     * Write-only credential payload — encrypted at rest.
     *
     * Structure: {username, password, privateKey, keyPassphrase}.
     * Never serialized to API responses. Encrypted/decrypted transparently
     * by the {@see \App\EventSubscriber\BookmarkCredentialsCipherListener}.
     */
    #[ApiProperty(readable: false)]
    #[ORM\Column(type: 'json')]
    private array $credentials = [];

    /**
     * Type-specific connection config (not encrypted).
     *
     * web:     {url}
     * sftp:    {remotePath}
     * database: {database}
     * rdp:     {domain}
     */
    #[ORM\Column(type: 'json')]
    private array $connectConfig = [];

    /** General notes (free text). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /** If false, the bookmark is hidden from lists (soft-disable). */
    #[ORM\Column]
    private bool $isEnabled = true;

    /** Workspace-wide (true) or personal (false). */
    #[ORM\Column]
    private bool $isShared = true;

    /** May this bookmark appear in the customer portal? Only web type. */
    #[ORM\Column]
    private bool $portalVisible = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CustomerSystem $system = null;

    /** If isShared=false, the personal owner. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $ownerUser = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $useCount = 0;

    // --- Getters / Setters ---

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $l): self { $this->label = $l; return $this; }

    public function getType(): BookmarkType { return $this->type; }
    public function setType(BookmarkType $t): self { $this->type = $t; return $this; }

    public function getHost(): string { return $this->host; }
    public function setHost(string $h): self { $this->host = $h; return $this; }

    public function getPort(): ?int { return $this->port; }
    public function setPort(?int $p): self { $this->port = $p; return $this; }

    public function getCredentials(): array { return $this->credentials; }
    public function setCredentials(array $c): self { $this->credentials = $c; return $this; }

    public function getConnectConfig(): array { return $this->connectConfig; }
    public function setConnectConfig(array $c): self { $this->connectConfig = $c; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }

    public function isEnabled(): bool { return $this->isEnabled; }
    public function setIsEnabled(bool $v): self { $this->isEnabled = $v; return $this; }

    public function isShared(): bool { return $this->isShared; }
    public function setIsShared(bool $v): self { $this->isShared = $v; return $this; }

    public function isPortalVisible(): bool { return $this->portalVisible; }
    public function setPortalVisible(bool $v): self { $this->portalVisible = $v; return $this; }

    public function getCustomer(): ?Customer { return $this->customer; }
    public function setCustomer(?Customer $c): self { $this->customer = $c; return $this; }

    public function getSystem(): ?CustomerSystem { return $this->system; }
    public function setSystem(?CustomerSystem $s): self { $this->system = $s; return $this; }

    public function getOwnerUser(): ?User { return $this->ownerUser; }
    public function setOwnerUser(?User $u): self { $this->ownerUser = $u; return $this; }

    public function getLastUsedAt(): ?\DateTimeImmutable { return $this->lastUsedAt; }
    public function setLastUsedAt(?\DateTimeImmutable $d): self { $this->lastUsedAt = $d; return $this; }

    public function getUseCount(): int { return $this->useCount; }
    public function setUseCount(int $n): self { $this->useCount = $n; return $this; }

    public function touch(): void { $this->lastUsedAt = new \DateTimeImmutable(); ++$this->useCount; }
}
