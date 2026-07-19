<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\OutboundMessageKind;
use App\Entity\Enum\OutboundMessageStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\OutboundMessageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Something Worktide wants to send out through a {@see Channel}.
 *
 * Lives independently of {@see InboundEvent} so the outbound
 * lifecycle (queue → send → ack → bounce → open/click) has its
 * own first-class state machine without mixing concerns. Reply-to-
 * thread is expressed as `conversationId` + `inReplyToInboundEvent`,
 * not by mutating an inbound row.
 *
 * Creation paths:
 *   1. User reply in the SPA conversation view
 *   2. System notification (watcher fired, maintenance reminder)
 *   3. AIRecommendation that the user approved
 *
 * `createdByUser` is required for messages to external recipients.
 * The OutboundQueue worker enforces this — auto-sending to a
 * customer without a named operator is forbidden by policy (see
 * AI-Vision: human-in-the-loop for external comms).
 *
 * `createdByRecommendation` (nullable) links back to the
 * AIRecommendation row when the message originated from an AI
 * suggestion — useful for audit and for showing "drafted by AI,
 * approved by Sven" in the activity feed.
 *
 * `externalId` is the provider's tracking-id (SMTP queue-id,
 * Graph internetMessageId, Mailgun message-id) — populated on send.
 */
#[ORM\Entity(repositoryClass: OutboundMessageRepository::class)]
#[ORM\Table(name: 'outbound_messages')]
#[ORM\Index(name: 'outbound_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'outbound_channel_idx', columns: ['channel_id'])]
#[ORM\Index(name: 'outbound_conversation_idx', columns: ['conversation_id'])]
#[ORM\Index(name: 'outbound_status_idx', columns: ['workspace_id', 'status'])]
#[ORM\Index(name: 'outbound_queued_idx', columns: ['status', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'OutboundMessage',
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'channel' => 'exact',
    'conversation' => 'exact',
    'status' => 'exact',
    'kind' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'sentAt'])]
#[ApiFilter(DateFilter::class, properties: ['createdAt', 'sentAt'])]
class OutboundMessage
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Channel $channel;

    /**
     * Raw recipient address as the operator typed it / as the
     * source thread captured it. Kept verbatim for audit even after
     * `recipientContact` resolves to a Contact record.
     */
    #[ORM\Column(length: 200)]
    private string $recipientRaw;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contact $recipientContact = null;

    /**
     * Additional recipients — Cc/Bcc for mail, mentioned-users for
     * slack, etc. JSON list of {address, contactIri?, kind: 'cc'|'bcc'}.
     *
     * @var array<int, array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $additionalRecipients = [];

    #[ORM\Column(length: 250, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: 'text')]
    private string $body = '';

    /**
     * Optional HTML variant of {@see $body}. When set, the mail is sent
     * as multipart/alternative (text + HTML); `body` stays the plaintext
     * fallback. Null for plaintext-only messages.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bodyHtml = null;

    /**
     * @var array<int, array<string, mixed>>  list of {fileIri, filename, mimeType, sizeBytes}
     */
    #[ORM\Column(type: 'json')]
    private array $attachments = [];

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Conversation $conversation = null;

    /**
     * The specific inbound event this message answers — drives the
     * In-Reply-To header for mail, the thread_ts for slack.
     */
    #[ORM\ManyToOne(targetEntity: InboundEvent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?InboundEvent $inReplyToInboundEvent = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdByUser = null;

    /**
     * Free-form FK to an AIRecommendation row (entity arrives in
     * Phase D). Stored as a UUID column so this entity can ship
     * before the AI tier exists; the FK constraint lands in the
     * Phase-D migration.
     */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?\Symfony\Component\Uid\Uuid $createdByRecommendationId = null;

    #[ORM\Column(length: 12, enumType: OutboundMessageStatus::class, options: ['default' => 'queued'])]
    private OutboundMessageStatus $status = OutboundMessageStatus::Queued;

    #[ORM\Column(length: 8, enumType: OutboundMessageKind::class, options: ['default' => 'reply'])]
    private OutboundMessageKind $kind = OutboundMessageKind::Reply;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $statusReason = null;

    #[ORM\Column(type: 'integer')]
    private int $attemptCount = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(length: 250, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $clickedAt = null;

    public function getChannel(): Channel { return $this->channel; }
    public function setChannel(Channel $c): self { $this->channel = $c; return $this; }

    public function getRecipientRaw(): string { return $this->recipientRaw; }
    public function setRecipientRaw(string $r): self { $this->recipientRaw = $r; return $this; }

    public function getRecipientContact(): ?Contact { return $this->recipientContact; }
    public function setRecipientContact(?Contact $c): self { $this->recipientContact = $c; return $this; }

    /** @return array<int, array<string, mixed>> */
    public function getAdditionalRecipients(): array { return $this->additionalRecipients; }
    /** @param array<int, array<string, mixed>> $r */
    public function setAdditionalRecipients(array $r): self { $this->additionalRecipients = $r; return $this; }

    public function getSubject(): ?string { return $this->subject; }
    public function setSubject(?string $s): self { $this->subject = $s; return $this; }

    public function getBody(): string { return $this->body; }
    public function setBody(string $b): self { $this->body = $b; return $this; }

    public function getBodyHtml(): ?string { return $this->bodyHtml; }
    public function setBodyHtml(?string $h): self { $this->bodyHtml = $h; return $this; }

    /** @return array<int, array<string, mixed>> */
    public function getAttachments(): array { return $this->attachments; }
    /** @param array<int, array<string, mixed>> $a */
    public function setAttachments(array $a): self { $this->attachments = $a; return $this; }

    public function getConversation(): ?Conversation { return $this->conversation; }
    public function setConversation(?Conversation $c): self { $this->conversation = $c; return $this; }

    public function getInReplyToInboundEvent(): ?InboundEvent { return $this->inReplyToInboundEvent; }
    public function setInReplyToInboundEvent(?InboundEvent $e): self { $this->inReplyToInboundEvent = $e; return $this; }

    public function getCreatedByUser(): ?User { return $this->createdByUser; }
    public function setCreatedByUser(?User $u): self { $this->createdByUser = $u; return $this; }

    public function getCreatedByRecommendationId(): ?\Symfony\Component\Uid\Uuid { return $this->createdByRecommendationId; }
    public function setCreatedByRecommendationId(?\Symfony\Component\Uid\Uuid $id): self { $this->createdByRecommendationId = $id; return $this; }

    public function getKind(): OutboundMessageKind { return $this->kind; }
    public function setKind(OutboundMessageKind $kind): self { $this->kind = $kind; return $this; }

    public function getStatus(): OutboundMessageStatus { return $this->status; }
    public function setStatus(OutboundMessageStatus $s): self { $this->status = $s; return $this; }

    public function getStatusReason(): ?string { return $this->statusReason; }
    public function setStatusReason(?string $r): self { $this->statusReason = $r; return $this; }

    public function getAttemptCount(): int { return $this->attemptCount; }
    public function setAttemptCount(int $n): self { $this->attemptCount = $n; return $this; }
    public function incrementAttempts(): self { $this->attemptCount++; return $this; }

    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function setSentAt(?\DateTimeImmutable $t): self { $this->sentAt = $t; return $this; }

    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(?string $id): self { $this->externalId = $id; return $this; }

    public function getOpenedAt(): ?\DateTimeImmutable { return $this->openedAt; }
    public function setOpenedAt(?\DateTimeImmutable $t): self { $this->openedAt = $t; return $this; }

    public function getClickedAt(): ?\DateTimeImmutable { return $this->clickedAt; }
    public function setClickedAt(?\DateTimeImmutable $t): self { $this->clickedAt = $t; return $this; }
}
