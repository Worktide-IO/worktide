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
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\FileTarget;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\FolderRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A folder in the Nextcloud-like file tree. Polymorphic exactly like {@see File}
 * (attached to a target entity via target+targetId, e.g. a Customer) with a
 * self-referential `parent` for nesting (null = root of that target's tree).
 *
 * Files live in a folder via {@see File::$folder} (null = the target's root).
 * Deletion is recursive and goes through {@see \App\Service\FolderService} +
 * FolderController (soft-delete of the whole subtree), so there is no API Delete
 * operation here.
 *
 * `isHiddenForConnectUsers` gates portal visibility, same semantics as on File.
 */
#[ORM\Entity(repositoryClass: FolderRepository::class)]
#[ORM\Table(name: 'folders')]
#[ORM\Index(name: 'folder_target_idx', columns: ['target', 'target_id'])]
#[ORM\Index(name: 'folder_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Folder',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object)"),
        new Post(securityPostDenormalize: "is_granted('EDIT', object)"),
        new Patch(security: "is_granted('EDIT', object)"),
        // No Delete op — deletion is recursive via FolderController.
    ],
    mercure: true,
)]
// SearchFilter can't match the enum `target` / uuid `targetId` scalar columns,
// so use BackedEnumFilter + UuidExactFilter (same as AIRecommendation). `parent`
// is an association, so SearchFilter/ExistsFilter work on it normally.
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'parent' => 'exact',
    'name' => 'partial',
])]
#[ApiFilter(BackedEnumFilter::class, properties: ['target'])]
#[ApiFilter(UuidExactFilter::class, properties: ['targetId'])]
#[ApiFilter(ExistsFilter::class, properties: ['parent'])]
#[ApiFilter(BooleanFilter::class, properties: ['isHiddenForConnectUsers'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
class Folder
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 16, enumType: FileTarget::class)]
    private FileTarget $target;

    #[ORM\Column(type: 'uuid')]
    private Uuid $targetId;

    #[ORM\Column(length: 255)]
    private string $name;

    /** Parent folder, or null for a root-level folder of the target's tree. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    #[ORM\Column]
    private bool $isHiddenForConnectUsers = false;

    public function getTarget(): FileTarget { return $this->target; }
    public function setTarget(FileTarget $target): self { $this->target = $target; return $this; }

    public function getTargetId(): Uuid { return $this->targetId; }
    public function setTargetId(Uuid $id): self { $this->targetId = $id; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getParent(): ?self { return $this->parent; }
    public function setParent(?self $parent): self { $this->parent = $parent; return $this; }

    public function isHiddenForConnectUsers(): bool { return $this->isHiddenForConnectUsers; }
    public function setIsHiddenForConnectUsers(bool $v): self { $this->isHiddenForConnectUsers = $v; return $this; }
}
