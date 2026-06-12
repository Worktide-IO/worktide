<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
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
use App\Entity\Enum\CommentTarget;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\CommentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Polymorphic comment attached to a Project / Task / Document (extensible).
 *
 * Mirrors awork's pinning + threading semantics:
 *  - `parent` enables one-level reply threads
 *  - `pinnedAt` + `pinnedBy` flag a comment as "important" (sticks to the top)
 *  - `editedAt` differentiates the original post from edits (clients badge it)
 *  - `mentions` is a JSON array of user UUIDs referenced via @-syntax; the
 *    notification subsystem (later) will read this column.
 *
 * Privacy: comments inherit visibility from their parent — voter delegates.
 */
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comments')]
#[ORM\Index(name: 'comment_target_idx', columns: ['target', 'target_id'])]
#[ORM\Index(name: 'comment_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'comment_author_idx', columns: ['author_id'])]
#[ORM\Index(name: 'comment_parent_idx', columns: ['parent_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Comment',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object)"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object)"),
        new Delete(security: "is_granted('DELETE', object)"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'target' => 'exact',
    'targetId' => 'exact',
    'author' => 'exact',
    'parent' => 'exact',
    'content' => 'partial',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isResolved'])]
#[ApiFilter(ExistsFilter::class, properties: ['pinnedAt', 'parent', 'editedAt'])]
#[ApiFilter(DateFilter::class, properties: ['createdAt', 'updatedAt'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'pinnedAt'])]
class Comment
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;

    #[ORM\Column(length: 16, enumType: CommentTarget::class)]
    private CommentTarget $target;

    #[ORM\Column(type: 'uuid')]
    private Uuid $targetId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Comment $parent = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pinnedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $pinnedBy = null;

    /** @var list<string> UUIDs of mentioned users (RFC4122 strings). */
    #[ORM\Column(type: 'json')]
    private array $mentions = [];

    #[ORM\Column]
    private bool $isResolved = false;

    public function getTarget(): CommentTarget
    {
        return $this->target;
    }

    public function setTarget(CommentTarget $target): self
    {
        $this->target = $target;
        return $this;
    }

    public function getTargetId(): Uuid
    {
        return $this->targetId;
    }

    public function setTargetId(Uuid $id): self
    {
        $this->targetId = $id;
        return $this;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function setAuthor(User $author): self
    {
        $this->author = $author;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getEditedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function markEdited(): self
    {
        $this->editedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getParent(): ?Comment
    {
        return $this->parent;
    }

    public function setParent(?Comment $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    public function getPinnedAt(): ?\DateTimeImmutable
    {
        return $this->pinnedAt;
    }

    public function isPinned(): bool
    {
        return $this->pinnedAt !== null;
    }

    public function pin(User $by): self
    {
        $this->pinnedAt = new \DateTimeImmutable();
        $this->pinnedBy = $by;
        return $this;
    }

    public function unpin(): self
    {
        $this->pinnedAt = null;
        $this->pinnedBy = null;
        return $this;
    }

    public function getPinnedBy(): ?User
    {
        return $this->pinnedBy;
    }

    /** @return list<string> */
    public function getMentions(): array
    {
        return $this->mentions;
    }

    /** @param list<string> $userUuids */
    public function setMentions(array $userUuids): self
    {
        $this->mentions = array_values(array_unique($userUuids));
        return $this;
    }

    public function isResolved(): bool
    {
        return $this->isResolved;
    }

    public function setIsResolved(bool $value): self
    {
        $this->isResolved = $value;
        return $this;
    }
}
