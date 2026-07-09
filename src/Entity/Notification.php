<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\NotificationType;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A single inbox notification for one recipient.
 *
 * Shared by BOTH audiences: staff ({@see User} with ROLE_USER) and portal
 * customers (a {@see User} with ROLE_PORTAL, linked from a {@see Contact}).
 * Because a portal user is also a User row, one `recipient` FK serves both;
 * the audience-specific SPA deep-link (`/tasks/{id}` vs `/tickets/{id}`) is
 * baked into `link` by the resolver at creation time.
 *
 * Rows are produced by {@see \App\Notification\NotificationDispatcher} from
 * the immutable {@see DomainEventLog} stream — never written by clients, so
 * there is deliberately NO `#[ApiResource]`. The read/list surface lives in
 * custom controllers (`/v1/me/notifications*` for staff, `/v1/portal/
 * notifications*` for portal) that the firewall split forces us to keep
 * separate anyway.
 *
 * `sourceEventId` + the unique index make dispatch idempotent: re-processing
 * the same domain event never duplicates a notification for a recipient.
 *
 * IMPORTANT: Notification must NOT be added to DomainEventEmitterSubscriber's
 * tracked-entity list — doing so would make fan-out recurse (a notification
 * write would emit a domain event that fans out another notification…).
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'notification_recipient_read_idx', columns: ['recipient_id', 'read_at'])]
#[ORM\Index(name: 'notification_recipient_id_idx', columns: ['recipient_id', 'id'])]
#[ORM\UniqueConstraint(
    name: 'notification_dedupe',
    columns: ['recipient_id', 'source_event_id', 'type'],
)]
#[ORM\HasLifecycleCallbacks]
class Notification
{
    use EntityIdTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Workspace $workspace = null;

    #[ORM\Column(length: 32, enumType: NotificationType::class)]
    private NotificationType $type;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    #[ORM\Column(length: 512)]
    private string $link;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    /**
     * The {@see DomainEventLog} id that produced this notification. Nullable
     * so hand-crafted/system notifications without an event source are still
     * expressible; part of the dedupe unique index.
     */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $sourceEventId = null;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(
        User $recipient,
        NotificationType $type,
        string $title,
        string $link,
        ?string $body = null,
        ?Workspace $workspace = null,
        ?User $actor = null,
        ?Uuid $sourceEventId = null,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        $this->recipient = $recipient;
        $this->type = $type;
        $this->title = $title;
        $this->link = $link;
        $this->body = $body;
        $this->workspace = $workspace;
        $this->actor = $actor;
        $this->sourceEventId = $sourceEventId;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getLink(): string
    {
        return $this->link;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getSourceEventId(): ?Uuid
    {
        return $this->sourceEventId;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function markRead(?\DateTimeImmutable $at = null): self
    {
        $this->readAt ??= $at ?? new \DateTimeImmutable();

        return $this;
    }
}
