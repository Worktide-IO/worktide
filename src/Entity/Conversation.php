<?php

declare(strict_types=1);

namespace App\Entity;

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
use App\Entity\Enum\ConversationStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TaggableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Aggregate of {@see InboundEvent}s + {@see OutboundMessage}s that
 * belong to the same logical thread.
 *
 * Only threading-capable channels populate this entity — Mail (via
 * Message-ID / References), Slack (thread_ts), WhatsApp (chat-id).
 * Channels without thread semantics (Zabbix alerts, CVE feeds,
 * monitoring webhooks) leave their InboundEvents standalone with
 * `conversationId = NULL`.
 *
 * `threadKey` is the channel-specific identifier the
 * `ConversationThreader` strategy uses to match incoming events to
 * an existing conversation. For mail it's the root References
 * value; for slack it's the thread_ts of the root message.
 *
 * `customer`/`assignee` are resolved at first ingest time and may be
 * overridden manually in the SPA later.
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'conversations')]
#[ORM\UniqueConstraint(name: 'conversation_channel_thread_unique', columns: ['channel_id', 'thread_key'])]
#[ORM\Index(name: 'conversation_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'conversation_status_idx', columns: ['workspace_id', 'status'])]
#[ORM\Index(name: 'conversation_customer_idx', columns: ['customer_id'])]
#[ORM\Index(name: 'conversation_assignee_idx', columns: ['assignee_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Conversation',
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        // Soft-delete (row kept + hidden from reads) is applied globally by
        // SoftDeleteRemoveProcessorDecorator; no per-operation wiring needed.
        new Delete(),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'channel' => 'exact',
    'status' => 'exact',
    'customer' => 'exact',
    'assignee' => 'exact',
    'tags.id' => 'exact',
    'subject' => 'partial',
])]
#[ApiFilter(OrderFilter::class, properties: ['lastEventAt', 'createdAt', 'subject'])]
#[ApiFilter(DateFilter::class, properties: ['lastEventAt', 'createdAt'])]
// Muted conversations (auto-suppressed noise like 2FA codes) stay fully stored
// and searchable; the SPA excludes them from the default inbox with
// `exists[mutedAt]=false` and surfaces them in the "Ignoriert" view with `true`.
#[ApiFilter(ExistsFilter::class, properties: ['mutedAt'])]
class Conversation implements TaggableInterface
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;
    use TaggableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Channel $channel;

    #[ORM\Column(length: 250)]
    private string $subject = '';

    #[ORM\Column(length: 250)]
    private string $threadKey;

    #[ORM\Column(length: 12, enumType: ConversationStatus::class, options: ['default' => 'open'])]
    private ConversationStatus $status = ConversationStatus::Open;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignee = null;

    /**
     * Free-text sender label captured at first-ingest — useful when
     * the Contact resolver hasn't matched a Customer yet. Falls back
     * to the raw From-header value for mail.
     */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $senderRaw = null;

    /**
     * Timestamp of the most recent inbound or outbound event in the
     * thread. Drives the default inbox ordering. Updated by the
     * ingest pipeline + outbound worker.
     */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastEventAt;

    /** @var array<int, mixed>  participant IRIs (Contact + User) for quick presence rendering */
    #[ORM\Column(type: 'json')]
    private array $participantIris = [];

    /**
     * Set when an {@see \App\Entity\InboundMuteRule} suppressed this thread
     * (e.g. 2FA/verification noise). The row stays fully stored + searchable —
     * this only hides it from the default inbox and skips automation/AI. Null =
     * a normal, visible conversation. Reversible (clear to un-mute).
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $mutedAt = null;

    public function __construct()
    {
        $this->lastEventAt = new \DateTimeImmutable();
        $this->tags = new ArrayCollection();
    }

    public function getChannel(): Channel { return $this->channel; }
    public function setChannel(Channel $c): self { $this->channel = $c; return $this; }

    public function getSubject(): string { return $this->subject; }
    public function setSubject(string $s): self { $this->subject = $s; return $this; }

    public function getThreadKey(): string { return $this->threadKey; }
    public function setThreadKey(string $k): self { $this->threadKey = $k; return $this; }

    public function getStatus(): ConversationStatus { return $this->status; }
    public function setStatus(ConversationStatus $s): self { $this->status = $s; return $this; }

    public function getCustomer(): ?Customer { return $this->customer; }
    public function setCustomer(?Customer $c): self { $this->customer = $c; return $this; }

    public function getAssignee(): ?User { return $this->assignee; }
    public function setAssignee(?User $u): self { $this->assignee = $u; return $this; }

    public function getSenderRaw(): ?string { return $this->senderRaw; }
    public function setSenderRaw(?string $s): self { $this->senderRaw = $s; return $this; }

    public function getLastEventAt(): \DateTimeImmutable { return $this->lastEventAt; }
    public function setLastEventAt(\DateTimeImmutable $t): self { $this->lastEventAt = $t; return $this; }

    public function getMutedAt(): ?\DateTimeImmutable { return $this->mutedAt; }
    public function setMutedAt(?\DateTimeImmutable $t): self { $this->mutedAt = $t; return $this; }

    /** @return array<int, mixed> */
    public function getParticipantIris(): array { return $this->participantIris; }
    /** @param array<int, mixed> $iris */
    public function setParticipantIris(array $iris): self { $this->participantIris = array_values(array_unique($iris)); return $this; }
}
