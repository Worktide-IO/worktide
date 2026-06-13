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
use App\Repository\DocumentSpaceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Container for Documents — equivalent to a Notion/Confluence "space" or a
 * top-level sidebar group. Documents can also exist without a space (private
 * notes); when both exist, space ordering wins over project ordering.
 *
 * Per-space access (contributors / team access) is deferred — for the MVP a
 * space is workspace-wide-readable; the writer-restriction is on the
 * Document itself via DocumentContributor.
 */
#[ORM\Entity(repositoryClass: DocumentSpaceRepository::class)]
#[ORM\Table(name: 'document_spaces')]
#[ORM\UniqueConstraint(name: 'document_space_workspace_name_unique', columns: ['workspace_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'DocumentSpace',
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
#[ApiFilter(OrderFilter::class, properties: ['position', 'name', 'createdAt'])]
class DocumentSpace
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $icon = null;

    /** Emoji shortcode (or actual emoji) the UI uses as space icon. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $emoji = null;

    #[ORM\Column(type: 'float')]
    private float $position = 0.0;

    #[ORM\Column]
    private bool $isArchived = false;

    /** @var Collection<int, Document> */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'space', orphanRemoval: false)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $documents;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $desc): self { $this->description = $desc; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function getIcon(): ?string { return $this->icon; }
    public function setIcon(?string $icon): self { $this->icon = $icon; return $this; }

    public function getEmoji(): ?string { return $this->emoji; }
    public function setEmoji(?string $emoji): self { $this->emoji = $emoji; return $this; }

    public function getPosition(): float { return $this->position; }
    public function setPosition(float $p): self { $this->position = $p; return $this; }

    public function isArchived(): bool { return $this->isArchived; }
    public function setIsArchived(bool $v): self { $this->isArchived = $v; return $this; }

    /** @return Collection<int, Document> */
    public function getDocuments(): Collection { return $this->documents; }
}
