<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\DocumentBodyFormat;
use App\Entity\Enum\DocumentWorkflowState;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wiki-style document — rich-text body, hierarchical via parent self-FK,
 * grouped into a DocumentSpace OR attached to a Project / Task.
 *
 * Soft-delete + workspace scope + versioning all standard. Content lives
 * in `body` as text; `bodyFormat` tells clients how to render it. For the
 * MVP we ship markdown; richtext (JSON tree) is reserved for when we
 * pick a structured editor.
 *
 * Privacy & sharing:
 *   isPrivate=true  → only the author + explicit DocumentContributors see it
 *   isPrivate=false → workspace members see it (additionally restricted by
 *                     isHiddenForConnectUsers for external Project members)
 *   Contributors via DocumentContributor entity grant read/manage to specific
 *   users beyond the default rules.
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'documents')]
#[ORM\Index(name: 'document_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'document_space_idx', columns: ['space_id'])]
#[ORM\Index(name: 'document_parent_idx', columns: ['parent_id'])]
#[ORM\Index(name: 'document_project_idx', columns: ['project_id'])]
#[ORM\Index(name: 'document_task_idx', columns: ['task_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Document',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object)"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object)"),
        new Delete(security: "is_granted('DELETE', object)"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'space' => 'exact',
    'parent' => 'exact',
    'project' => 'exact',
    'task' => 'exact',
    'name' => 'partial',
])]
#[ApiFilter(SearchFilter::class, properties: ['workflowState' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['isPrivate', 'isHiddenForConnectUsers', 'isArchived'])]
#[ApiFilter(ExistsFilter::class, properties: ['parent', 'space', 'project', 'task', 'deletedAt'])]
#[ApiFilter(OrderFilter::class, properties: ['position', 'name', 'updatedAt'])]
class Document
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 200)]
    private string $name = 'Untitled';

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $emoji = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    #[ORM\Column(length: 12, enumType: DocumentBodyFormat::class)]
    private DocumentBodyFormat $bodyFormat = DocumentBodyFormat::Markdown;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DocumentSpace $space = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Document $parent = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Task $task = null;

    #[ORM\Column(type: 'float')]
    private float $position = 0.0;

    #[ORM\Column]
    private bool $isPrivate = false;

    #[ORM\Column]
    private bool $isHiddenForConnectUsers = false;

    #[ORM\Column]
    private bool $isArchived = false;

    /** @var Collection<int, DocumentContributor> */
    #[ORM\OneToMany(targetEntity: DocumentContributor::class, mappedBy: 'document', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $contributors;

    /** @var Collection<int, DocumentRevision> */
    #[ORM\OneToMany(targetEntity: DocumentRevision::class, mappedBy: 'document', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $revisions;

    #[ORM\Column(length: 12, enumType: DocumentWorkflowState::class, options: ['default' => 'draft'])]
    private DocumentWorkflowState $workflowState = DocumentWorkflowState::Draft;

    /**
     * Users assigned to review the document while it sits in the `review`
     * state. Assignment is independent of contributors (reviewers may
     * read-only) — when the state is `draft` or `published`, this list
     * is informational only.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'document_reviewers')]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $reviewers;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $submittedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $publishedBy = null;

    public function __construct()
    {
        $this->contributors = new ArrayCollection();
        $this->revisions = new ArrayCollection();
        $this->reviewers = new ArrayCollection();
    }

    /** @return Collection<int, DocumentRevision> */
    public function getRevisions(): Collection { return $this->revisions; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getEmoji(): ?string { return $this->emoji; }
    public function setEmoji(?string $emoji): self { $this->emoji = $emoji; return $this; }

    public function getBody(): ?string { return $this->body; }
    public function setBody(?string $body): self { $this->body = $body; return $this; }

    public function getBodyFormat(): DocumentBodyFormat { return $this->bodyFormat; }
    public function setBodyFormat(DocumentBodyFormat $format): self { $this->bodyFormat = $format; return $this; }

    public function getSpace(): ?DocumentSpace { return $this->space; }
    public function setSpace(?DocumentSpace $space): self { $this->space = $space; return $this; }

    public function getParent(): ?Document { return $this->parent; }
    public function setParent(?Document $parent): self { $this->parent = $parent; return $this; }

    public function getProject(): ?Project { return $this->project; }
    public function setProject(?Project $project): self { $this->project = $project; return $this; }

    public function getTask(): ?Task { return $this->task; }
    public function setTask(?Task $task): self { $this->task = $task; return $this; }

    public function getPosition(): float { return $this->position; }
    public function setPosition(float $p): self { $this->position = $p; return $this; }

    public function isPrivate(): bool { return $this->isPrivate; }
    public function setIsPrivate(bool $v): self { $this->isPrivate = $v; return $this; }

    public function isHiddenForConnectUsers(): bool { return $this->isHiddenForConnectUsers; }
    public function setIsHiddenForConnectUsers(bool $v): self { $this->isHiddenForConnectUsers = $v; return $this; }

    public function isArchived(): bool { return $this->isArchived; }
    public function setIsArchived(bool $v): self { $this->isArchived = $v; return $this; }

    /** @return Collection<int, DocumentContributor> */
    public function getContributors(): Collection { return $this->contributors; }

    public function getWorkflowState(): DocumentWorkflowState { return $this->workflowState; }
    public function setWorkflowState(DocumentWorkflowState $s): self { $this->workflowState = $s; return $this; }

    /** @return Collection<int, User> */
    public function getReviewers(): Collection { return $this->reviewers; }

    public function addReviewer(User $u): self
    {
        if (!$this->reviewers->contains($u)) {
            $this->reviewers->add($u);
        }
        return $this;
    }

    public function removeReviewer(User $u): self
    {
        $this->reviewers->removeElement($u);
        return $this;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable { return $this->submittedAt; }
    public function setSubmittedAt(?\DateTimeImmutable $t): self { $this->submittedAt = $t; return $this; }

    public function getSubmittedBy(): ?User { return $this->submittedBy; }
    public function setSubmittedBy(?User $u): self { $this->submittedBy = $u; return $this; }

    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $t): self { $this->publishedAt = $t; return $this; }

    public function getPublishedBy(): ?User { return $this->publishedBy; }
    public function setPublishedBy(?User $u): self { $this->publishedBy = $u; return $this; }
}
