<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Entity\Enum\InboundEventState;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\InboundEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing that came in from the outside world. Source-agnostic
 * representation that every {@see Channel} adapter writes.
 *
 * For mail, one event = one received message. For a webhook, one
 * event = one POST. For an RSS adapter, one event = one new feed
 * item.
 *
 * `externalId` is whatever the source uses to identify the item
 * (Message-ID for mail, Zabbix event-id, webhook delivery-id). The
 * UNIQUE(channel, externalId) index makes ingestion idempotent —
 * pulling the same mailbox twice doesn't create duplicates.
 *
 * `conversationId` is populated for threading-capable adapters via
 * the `ConversationThreader` strategy. For non-threading sources
 * it stays NULL and the event is a standalone item.
 *
 * `body` is normalised plain text or markdown — adapters that
 * receive richer formats (HTML mail, Slack mrkdwn) should also
 * stash the original payload in `sourceMetadata.raw` for later
 * re-rendering.
 *
 * Soft-deletion isn't applied here on purpose — `state=Dismissed`
 * is the soft-delete; the row stays for AI-training and audit.
 *
 * Operations are read-only (Get/GetCollection) plus PATCH-state.
 * Creation goes through the adapter pipeline, not the API.
 */
#[ORM\Entity(repositoryClass: InboundEventRepository::class)]
#[ORM\Table(name: 'inbound_events')]
#[ORM\UniqueConstraint(name: 'inbound_event_channel_external_unique', columns: ['channel_id', 'external_id'])]
#[ORM\Index(name: 'inbound_event_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'inbound_event_conversation_idx', columns: ['conversation_id'])]
#[ORM\Index(name: 'inbound_event_state_idx', columns: ['workspace_id', 'state'])]
#[ORM\Index(name: 'inbound_event_received_idx', columns: ['received_at'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'InboundEvent',
    operations: [
        new GetCollection(),
        new Get(),
        new Patch(),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'channel' => 'exact',
    'conversation' => 'exact',
    'state' => 'exact',
    'externalId' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['receivedAt', 'createdAt'])]
#[ApiFilter(DateFilter::class, properties: ['receivedAt', 'createdAt'])]
class InboundEvent
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Channel $channel;

    #[ORM\Column(length: 250)]
    private string $externalId;

    /**
     * Free-text label for the originator — for mail, the From-header
     * value verbatim; for slack, the user-display-name. Useful when
     * Contact resolution didn't yield a match.
     */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $senderRaw = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contact $senderContact = null;

    /**
     * Optional first-line summary (mail Subject, slack message
     * snippet). Helps the inbox-list render without loading the
     * full body.
     */
    #[ORM\Column(length: 250, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    /**
     * Attachments as a list of references — {filename, mimeType,
     * sizeBytes, fileIri (when persisted to File entity), externalUrl
     * (when held by the source)}. Concrete shape is adapter-defined.
     *
     * @var array<int, array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $attachments = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $sourceMetadata = [];

    /**
     * Deep-link back to the message inside the source system if it
     * has a stable URL (Slack permalink, MS Graph webLink). Lets the
     * SPA say "open in Slack" instead of just rendering our snapshot.
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $traceUrl = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Conversation $conversation = null;

    #[ORM\Column(length: 12, enumType: InboundEventState::class, options: ['default' => 'pending'])]
    private InboundEventState $state = InboundEventState::Pending;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    public function __construct()
    {
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getChannel(): Channel { return $this->channel; }
    public function setChannel(Channel $c): self { $this->channel = $c; return $this; }

    public function getExternalId(): string { return $this->externalId; }
    public function setExternalId(string $id): self { $this->externalId = $id; return $this; }

    public function getSenderRaw(): ?string { return $this->senderRaw; }
    public function setSenderRaw(?string $s): self { $this->senderRaw = $s; return $this; }

    public function getSenderContact(): ?Contact { return $this->senderContact; }
    public function setSenderContact(?Contact $c): self { $this->senderContact = $c; return $this; }

    public function getSubject(): ?string { return $this->subject; }
    public function setSubject(?string $s): self { $this->subject = $s; return $this; }

    public function getBody(): ?string { return $this->body; }
    public function setBody(?string $b): self { $this->body = $b; return $this; }

    /** @return array<int, array<string, mixed>> */
    public function getAttachments(): array { return $this->attachments; }
    /** @param array<int, array<string, mixed>> $a */
    public function setAttachments(array $a): self { $this->attachments = $a; return $this; }

    /** @return array<string, mixed> */
    public function getSourceMetadata(): array { return $this->sourceMetadata; }
    /** @param array<string, mixed> $m */
    public function setSourceMetadata(array $m): self { $this->sourceMetadata = $m; return $this; }

    public function getTraceUrl(): ?string { return $this->traceUrl; }
    public function setTraceUrl(?string $u): self { $this->traceUrl = $u; return $this; }

    public function getConversation(): ?Conversation { return $this->conversation; }
    public function setConversation(?Conversation $c): self { $this->conversation = $c; return $this; }

    public function getState(): InboundEventState { return $this->state; }
    public function setState(InboundEventState $s): self { $this->state = $s; return $this; }

    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function setReceivedAt(\DateTimeImmutable $t): self { $this->receivedAt = $t; return $this; }
}
