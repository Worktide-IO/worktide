<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\SocialPostTargetStatus;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\SocialPostTargetRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One network destination of a {@see SocialPost}. Carries the per-network text
 * variant (or null to use the parent body), the target {@see Channel}
 * (adapterCode `social_*`), and the delivery outcome.
 *
 * Clients create targets (one per network) and may set the per-network
 * `bodyOverride`; everything else (status, externalId, permalink, …) is owned
 * by the publisher worker and not writable over the API. The only other
 * client-driven write is the retry action
 * ({@see \App\Controller\Api\SocialPostTargetRetryController}).
 */
#[ORM\Entity(repositoryClass: SocialPostTargetRepository::class)]
#[ORM\Table(name: 'social_post_targets')]
#[ORM\Index(name: 'social_target_post_idx', columns: ['social_post_id'])]
#[ORM\Index(name: 'social_target_status_idx', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'SocialPostTarget',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object)"),
        new Post(security: "is_granted('EDIT', object)"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'socialPost' => 'exact',
    'channel' => 'exact',
    'status' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'publishedAt'])]
class SocialPostTarget
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;

    /** Retried transient failures stop here so a broken network can't loop forever. */
    public const MAX_ATTEMPTS = 3;

    #[ORM\ManyToOne(targetEntity: SocialPost::class, inversedBy: 'targets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SocialPost $socialPost;

    #[ORM\ManyToOne(targetEntity: Channel::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Channel $channel;

    /** Per-network text variant; null falls back to the parent post body. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bodyOverride = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(length: 12, enumType: SocialPostTargetStatus::class, options: ['default' => 'queued'])]
    private SocialPostTargetStatus $status = SocialPostTargetStatus::Queued;

    /** Provider's post identifier once published. */
    #[ApiProperty(writable: false)]
    #[ORM\Column(length: 250, nullable: true)]
    private ?string $externalId = null;

    /** Public URL of the live post. */
    #[ApiProperty(writable: false)]
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $permalink = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorReason = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(type: 'integer')]
    private int $attemptCount = 0;

    #[ApiProperty(writable: false)]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function getSocialPost(): SocialPost { return $this->socialPost; }
    public function setSocialPost(SocialPost $p): self { $this->socialPost = $p; return $this; }

    public function getChannel(): Channel { return $this->channel; }
    public function setChannel(Channel $c): self { $this->channel = $c; return $this; }

    public function getBodyOverride(): ?string { return $this->bodyOverride; }
    public function setBodyOverride(?string $b): self { $this->bodyOverride = $b; return $this; }

    /** Effective text published to this network: the override, or the parent body. */
    public function effectiveBody(): string
    {
        return $this->bodyOverride ?? $this->socialPost->getBody();
    }

    public function getStatus(): SocialPostTargetStatus { return $this->status; }
    public function setStatus(SocialPostTargetStatus $s): self { $this->status = $s; return $this; }

    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(?string $id): self { $this->externalId = $id; return $this; }

    public function getPermalink(): ?string { return $this->permalink; }
    public function setPermalink(?string $url): self { $this->permalink = $url; return $this; }

    public function getErrorReason(): ?string { return $this->errorReason; }
    public function setErrorReason(?string $r): self { $this->errorReason = $r; return $this; }

    public function getAttemptCount(): int { return $this->attemptCount; }
    public function setAttemptCount(int $n): self { $this->attemptCount = $n; return $this; }
    public function incrementAttempts(): self { $this->attemptCount++; return $this; }

    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $t): self { $this->publishedAt = $t; return $this; }

    public function markPublished(string $externalId, ?string $permalink): self
    {
        $this->status = SocialPostTargetStatus::Published;
        $this->externalId = $externalId;
        $this->permalink = $permalink;
        $this->errorReason = null;
        $this->publishedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markFailed(string $reason): self
    {
        $this->status = SocialPostTargetStatus::Failed;
        $this->errorReason = $reason;
        return $this;
    }
}
