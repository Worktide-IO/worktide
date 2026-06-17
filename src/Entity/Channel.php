<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\ChannelCapability;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ChannelRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Source-agnostic communication endpoint for a workspace.
 *
 * A Channel is a configuration row that binds an in-code adapter
 * (`adapterCode` — e.g. `email_imap`, `email_graph`, `slack_bot`,
 * `zabbix_webhook`) to a workspace, holds its auth + endpoint config,
 * and declares whether it carries inbound, outbound, or both.
 *
 * Mails are the canonical both-capable channel (IMAP-Pull + SMTP-Send
 * on the same Mailbox). A Zabbix webhook is inbound-only. A
 * transactional Mailgun setup is outbound-only.
 *
 * Tier-1 implementation only ships the three email adapters
 * (email_imap / email_graph / email_gmail). Slack/Zabbix/Twilio/etc.
 * dock onto this entity in later phases without a schema migration —
 * adding a new value to the `adapterCode` whitelist is enough.
 *
 * `authConfig` is stored as JSON and is meant to be **libsodium-
 * encrypted at rest** by an Doctrine event-listener (TBD in C.2). The
 * raw column never holds passwords/tokens in cleartext.
 *
 * `inboundConfig` / `outboundConfig` are adapter-specific shapes
 * (IMAP host/port/folder, Graph user-id, Gmail labelIds, …). The
 * adapter declares its expected schema; this entity stores it
 * opaque.
 */
#[ORM\Entity(repositoryClass: ChannelRepository::class)]
#[ORM\Table(name: 'channels')]
#[ORM\UniqueConstraint(name: 'channel_workspace_name_unique', columns: ['workspace_id', 'name'])]
#[ORM\Index(name: 'channel_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'channel_adapter_idx', columns: ['adapter_code'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Channel',
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
    'adapterCode' => 'exact',
    'name' => 'partial',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isEnabled', 'isShared'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
class Channel
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 80)]
    private string $name;

    /**
     * Identifies the adapter class that handles this channel —
     * `email_imap`, `email_graph`, `email_gmail`, `slack_bot`,
     * `zabbix_webhook`, …
     *
     * Free-form string (not enum) so a new adapter can drop into the
     * codebase without an enum migration. The adapter registry
     * (Phase C.2) rejects unknown codes at save-time.
     */
    #[ORM\Column(length: 40)]
    private string $adapterCode;

    /**
     * Capabilities this channel exposes. JSON list of
     * {@see ChannelCapability} values.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $capabilities = [];

    /**
     * Worktide entity-type slugs this channel can sync — empty
     * array (the default) means the channel is event-stream-only.
     * Non-empty marks the channel as also implementing the
     * {@see \App\Channels\SyncableAdapter} interface for those
     * types.
     *
     * Examples:
     *   ['task', 'comment']           — Jira / Redmine
     *   ['calendar_event', 'time_entry'] — CalDAV / Google Calendar
     *
     * The {@see AdapterRegistry} verifies that the adapter
     * declared for `adapterCode` actually supports the listed
     * types when the channel is saved.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $entityTypes = [];

    /**
     * Per-channel address used by the AI / threading code to
     * decide which Customer / Contact a message belongs to.
     * For mail: typically a recipient address ("support@firma.de").
     * For slack: a channel-id. Null for outbound-only setups
     * where there's no inbound notion of "the address that
     * received this".
     */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $address = null;

    /** Adapter-specific inbound configuration (host, port, folder, …). @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $inboundConfig = [];

    /** Adapter-specific outbound configuration (smtp host, from, …). @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $outboundConfig = [];

    /**
     * Auth config (password, OAuth refresh-token, API-key). Stored
     * as opaque JSON and meant to be libsodium-encrypted at rest by
     * a Doctrine listener. Never log or expose this column verbatim.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $authConfig = [];

    /**
     * Shared = visible to the whole workspace (team mailbox).
     * Not shared = only visible to the creating user (personal
     * mailbox / personal slack bot). The voter consults this.
     */
    #[ORM\Column]
    private bool $isShared = true;

    #[ORM\Column]
    private bool $isEnabled = true;

    /**
     * Last successful pull / poll timestamp for inbound adapters.
     * Used by the scheduler to decide which channels are stale
     * and by the SPA to display "last sync" badges.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastSyncError = null;

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getAdapterCode(): string { return $this->adapterCode; }
    public function setAdapterCode(string $code): self { $this->adapterCode = $code; return $this; }

    /** @return list<string> */
    public function getCapabilities(): array { return $this->capabilities; }

    /** @param list<string|ChannelCapability> $caps */
    public function setCapabilities(array $caps): self
    {
        $out = [];
        foreach ($caps as $c) {
            if ($c instanceof ChannelCapability) {
                $out[] = $c->value;
            } elseif (is_string($c) && ChannelCapability::tryFrom($c) !== null) {
                $out[] = $c;
            }
        }
        $this->capabilities = array_values(array_unique($out));
        return $this;
    }

    public function supports(ChannelCapability $c): bool
    {
        return \in_array($c->value, $this->capabilities, true);
    }

    /** @return list<string> */
    public function getEntityTypes(): array { return $this->entityTypes; }

    /** @param list<string> $types */
    public function setEntityTypes(array $types): self
    {
        $this->entityTypes = array_values(array_unique(array_filter($types, 'is_string')));
        return $this;
    }

    public function supportsEntityType(string $type): bool
    {
        return \in_array($type, $this->entityTypes, true);
    }

    public function isEntitySyncEnabled(): bool
    {
        return $this->entityTypes !== [];
    }

    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $a): self { $this->address = $a; return $this; }

    /** @return array<string, mixed> */
    public function getInboundConfig(): array { return $this->inboundConfig; }
    /** @param array<string, mixed> $c */
    public function setInboundConfig(array $c): self { $this->inboundConfig = $c; return $this; }

    /** @return array<string, mixed> */
    public function getOutboundConfig(): array { return $this->outboundConfig; }
    /** @param array<string, mixed> $c */
    public function setOutboundConfig(array $c): self { $this->outboundConfig = $c; return $this; }

    /** @return array<string, mixed> */
    public function getAuthConfig(): array { return $this->authConfig; }
    /** @param array<string, mixed> $c */
    public function setAuthConfig(array $c): self { $this->authConfig = $c; return $this; }

    public function isShared(): bool { return $this->isShared; }
    public function setIsShared(bool $v): self { $this->isShared = $v; return $this; }

    public function isEnabled(): bool { return $this->isEnabled; }
    public function setIsEnabled(bool $v): self { $this->isEnabled = $v; return $this; }

    public function getLastSyncedAt(): ?\DateTimeImmutable { return $this->lastSyncedAt; }
    public function setLastSyncedAt(?\DateTimeImmutable $t): self { $this->lastSyncedAt = $t; return $this; }

    public function getLastSyncError(): ?string { return $this->lastSyncError; }
    public function setLastSyncError(?string $e): self { $this->lastSyncError = $e; return $this; }
}
