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
use App\Entity\Enum\NewsletterFrequency;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TaggableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\TranslatableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\NewsletterRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

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
#[ORM\UniqueConstraint(name: 'newsletter_ws_slug_uniq', columns: ['workspace_id', 'slug'])]
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
    'slug' => 'exact',
    'tags.id' => 'exact',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isArchived', 'isSubscribable', 'isMandatory'])]
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

    /**
     * Estimated send cadence — a subscriber-facing expectation hint, not an
     * enforced schedule. Null = not stated. Its human label is localised at the
     * boundary (portal DTO / staff UI), not stored translated.
     */
    #[ORM\Column(length: 20, nullable: true, enumType: NewsletterFrequency::class)]
    private ?NewsletterFrequency $estimatedFrequency = null;

    /**
     * Stable per-workspace handle (lowercase). Optional external reference for
     * imports / deep-links; unique per workspace. Lowercased on set (same
     * convention as AgreementType/Product — no slugger service).
     */
    #[ORM\Column(length: 64, nullable: true)]
    #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9_-]*$/', message: 'Slug must be lowercase letters, digits, dash or underscore.')]
    private ?string $slug = null;

    /** Lucide icon name for the UI (free-form string, frontend maps it). */
    #[ORM\Column(length: 40, options: ['default' => 'mail'])]
    private string $icon = 'mail';

    /** Hex accent color for the UI. */
    #[ORM\Column(length: 16, options: ['default' => '#94a3b8'])]
    private string $color = '#94a3b8';

    /** Retired: kept with its issues/history but hidden from the portal + staff default list. */
    #[ORM\Column(options: ['default' => false])]
    private bool $isArchived = false;

    /**
     * Whether contacts may opt in to THIS node. False = a pure structure/category
     * node that only gives the tree its shape, even if granted to a customer.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $isSubscribable = true;

    /**
     * Transactional/mandatory newsletter: every active contact of a customer that
     * has this node granted is a recipient, with no subscription row and no opt-out
     * (service announcements, not marketing). Consent-exempt by design — this is the
     * ONLY consent-free enrolment, deliberately not a marketing default opt-in.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $isMandatory = false;

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

    public function getEstimatedFrequency(): ?NewsletterFrequency
    {
        return $this->estimatedFrequency;
    }

    public function setEstimatedFrequency(?NewsletterFrequency $estimatedFrequency): self
    {
        $this->estimatedFrequency = $estimatedFrequency;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): self
    {
        $slug = $slug !== null ? strtolower(trim($slug)) : null;
        $this->slug = $slug === '' ? null : $slug;

        return $this;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function setIsArchived(bool $isArchived): self
    {
        $this->isArchived = $isArchived;

        return $this;
    }

    public function isSubscribable(): bool
    {
        return $this->isSubscribable;
    }

    public function setIsSubscribable(bool $isSubscribable): self
    {
        $this->isSubscribable = $isSubscribable;

        return $this;
    }

    public function isMandatory(): bool
    {
        return $this->isMandatory;
    }

    public function setIsMandatory(bool $isMandatory): self
    {
        $this->isMandatory = $isMandatory;

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
