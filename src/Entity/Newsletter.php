<?php

declare(strict_types=1);

namespace App\Entity;

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
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TaggableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\TranslatableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\NewsletterRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A node in the per-workspace newsletter/topic tree. Arbitrary nesting via the
 * self-referencing `parent`; siblings are ordered by `position`. Each node has a
 * title + optional description.
 *
 * Customers are granted individual nodes ({@see Customer::$enabledNewsletterIds});
 * their portal contacts then opt in/out per granted node
 * ({@see NewsletterSubscription}).
 *
 * Admin-only CRUD (workspace Owner/Admin). Tenant isolation comes from
 * {@see WorkspaceScopedTrait} + the read-side WorkspaceScopeExtension. The Post
 * uses `securityPostDenormalize` so the workspace grant is checked AFTER the
 * body's `workspace` (or the parent-derived one) is bound — a plain `security`
 * would evaluate `object.getWorkspace()` before it exists and pass vacuously
 * (the cross-tenant self-escalation class the security audit hardened).
 */
#[ORM\Entity(repositoryClass: NewsletterRepository::class)]
#[ORM\Table(name: 'newsletters')]
#[ORM\Index(name: 'newsletter_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'newsletter_parent_idx', columns: ['parent_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Newsletter',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(securityPostDenormalize: "is_granted('EDIT', object.getWorkspace())"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'parent' => 'exact',
    'title' => 'partial',
    'tags.id' => 'exact',
])]
#[ApiFilter(ExistsFilter::class, properties: ['parent', 'deletedAt'])]
#[ApiFilter(OrderFilter::class, properties: ['position', 'title', 'updatedAt'])]
class Newsletter implements TranslatableInterface, TaggableInterface
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use TranslatableTrait;
    use TaggableTrait;

    #[ORM\Column(length: 200)]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Newsletter $parent = null;

    /** Float so a node can be dropped between two siblings without renumbering. */
    #[ORM\Column(type: 'float')]
    private float $position = 0.0;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;
        // A child lives in its parent's workspace — this is the "auto-stamp"
        // convention (see IdeaVote::setIdea). Root nodes still need `workspace`
        // supplied in the create body.
        if ($parent !== null) {
            $this->setWorkspace($parent->getWorkspace());
        }

        return $this;
    }

    public function getPosition(): float
    {
        return $this->position;
    }

    public function setPosition(float $position): self
    {
        $this->position = $position;

        return $this;
    }

    /**
     * @return list<string>
     */
    public static function translatableFields(): array
    {
        return ['title', 'description'];
    }
}
