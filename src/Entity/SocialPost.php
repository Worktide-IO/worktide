<?php

declare(strict_types=1);

namespace App\Entity;

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
use App\Entity\Enum\SocialPostStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\SocialPostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single social-media composition fanned out to one or more networks.
 *
 * "Compose once → publish to many": the {@see $body} + {@see $mediaRefs} are
 * the shared content, while each {@see SocialPostTarget} carries the network it
 * goes to, an optional per-network text variant, and its own delivery state.
 * This separation mirrors {@see Conversation} / {@see OutboundMessage}: the
 * aggregate owns intent, the children own delivery.
 *
 * The connection/account credentials live on the existing {@see Channel}
 * (adapterCode `social_*`, capabilities `[outbound]`), so OAuth/secret-at-rest
 * (libsodium) and the channel CRUD/voter stack are all reused unchanged.
 *
 * Status flow ({@see SocialPostStatus}): Draft → PendingApproval → (Scheduled →)
 * Publishing → Published | PartiallyFailed | Failed. External publishing is
 * gated behind explicit approval (human-in-the-loop), never auto-sent.
 */
#[ORM\Entity(repositoryClass: SocialPostRepository::class)]
#[ORM\Table(name: 'social_posts')]
#[ORM\Index(name: 'social_post_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'social_post_status_idx', columns: ['status'])]
#[ORM\Index(name: 'social_post_due_idx', columns: ['status', 'scheduled_at'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'SocialPost',
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
    'status' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'scheduledAt', 'publishedAt'])]
class SocialPost
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    /** Shared base text; a target may override it with a per-network variant. */
    #[ORM\Column(type: 'text')]
    private string $body = '';

    /**
     * Attached media, referencing uploaded {@see File} rows.
     *
     * @var array<int, array<string, mixed>> list of {fileId, fileIri?, mimeType?, sizeBytes?, altText?}
     */
    #[ORM\Column(type: 'json')]
    private array $mediaRefs = [];

    /** Owned by the lifecycle actions (submit/approve/publish) + worker, not directly writable. */
    #[ApiProperty(writable: false)]
    #[ORM\Column(length: 16, enumType: SocialPostStatus::class, options: ['default' => 'draft'])]
    private SocialPostStatus $status = SocialPostStatus::Draft;

    /** When set and in the future, the post goes live via the publish-due command. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ApiProperty(writable: false)]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'approved_by_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedByUser = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /**
     * Optional owning project. Lets the customer portal scope posts to a
     * customer (via the project's customer + isExternal), the same way tickets
     * / documents / proposals are scoped. Null = workspace-level, not shown in
     * any portal.
     */
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    /** Latest change the customer requested from the portal ("Änderung anfordern"). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $changeRequestNote = null;

    /** @var Collection<int, SocialPostTarget> */
    #[ORM\OneToMany(mappedBy: 'socialPost', targetEntity: SocialPostTarget::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $targets;

    public function __construct()
    {
        $this->targets = new ArrayCollection();
    }

    public function getBody(): string { return $this->body; }
    public function setBody(string $body): self { $this->body = $body; return $this; }

    /** @return array<int, array<string, mixed>> */
    public function getMediaRefs(): array { return $this->mediaRefs; }
    /** @param array<int, array<string, mixed>> $refs */
    public function setMediaRefs(array $refs): self { $this->mediaRefs = array_values($refs); return $this; }

    public function getStatus(): SocialPostStatus { return $this->status; }
    public function setStatus(SocialPostStatus $status): self { $this->status = $status; return $this; }

    public function getScheduledAt(): ?\DateTimeImmutable { return $this->scheduledAt; }
    public function setScheduledAt(?\DateTimeImmutable $t): self { $this->scheduledAt = $t; return $this; }

    public function getApprovedByUser(): ?User { return $this->approvedByUser; }
    public function setApprovedByUser(?User $u): self { $this->approvedByUser = $u; return $this; }

    public function getApprovedAt(): ?\DateTimeImmutable { return $this->approvedAt; }
    public function setApprovedAt(?\DateTimeImmutable $t): self { $this->approvedAt = $t; return $this; }

    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $t): self { $this->publishedAt = $t; return $this; }

    public function getProject(): ?Project { return $this->project; }
    public function setProject(?Project $project): self { $this->project = $project; return $this; }

    public function getChangeRequestNote(): ?string { return $this->changeRequestNote; }
    public function setChangeRequestNote(?string $note): self { $this->changeRequestNote = $note; return $this; }

    /** @return Collection<int, SocialPostTarget> */
    public function getTargets(): Collection { return $this->targets; }

    public function addTarget(SocialPostTarget $target): self
    {
        if (!$this->targets->contains($target)) {
            $this->targets->add($target);
            $target->setSocialPost($this);
        }
        return $this;
    }

    public function removeTarget(SocialPostTarget $target): self
    {
        $this->targets->removeElement($target);
        return $this;
    }
}
