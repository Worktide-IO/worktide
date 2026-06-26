<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Enum\DiscoveredRecordState;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\DiscoveredExternalRecordRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An external ticket (Jira/Redmine) discovered during entity-sync that has no
 * {@see EntitySync} mapping yet AND involves a workspace person (assignee or
 * watcher) per {@see \App\Service\Inbound\InboundImportFilter}.
 *
 * `EntityApplier` (V1) never auto-creates local entities — it needs context the
 * external side lacks (a Task needs a Project). So instead of dropping unmapped
 * records, {@see \App\Service\Inbound\DiscoveredRecordCollector} parks the
 * relevant ones here, and an operator decides: import (new Task), link (existing
 * Task), or dismiss — via {@see \App\Controller\Api\DiscoveredExternalRecordActionsController}.
 *
 * `UNIQUE(channel, externalId)` makes capture idempotent across repeated
 * webhooks/pulls — the same external ticket updates its one row.
 *
 * Read-only over the API; the action endpoints own all writes.
 */
#[ORM\Entity(repositoryClass: DiscoveredExternalRecordRepository::class)]
#[ORM\Table(name: 'discovered_external_records')]
#[ORM\UniqueConstraint(name: 'discovered_record_channel_external_unique', columns: ['channel_id', 'external_id'])]
#[ORM\Index(name: 'discovered_record_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'discovered_record_state_idx', columns: ['state'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'DiscoveredExternalRecord',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('ROLE_USER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'channel' => 'exact',
    'state' => 'exact',
    'entityType' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'title'])]
class DiscoveredExternalRecord
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Channel $channel;

    /** Worktide-shape entity-type slug the snapshot maps to (e.g. `task`). */
    #[ORM\Column(length: 40)]
    private string $entityType;

    #[ORM\Column(name: 'external_id', length: 200)]
    private string $externalId;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $externalUrl = null;

    /** Display preview — the snapshot's title field. */
    #[ORM\Column(length: 250)]
    private string $title = '';

    /**
     * The snapshot's Worktide-shape field map, kept verbatim so an import can
     * build the local entity without re-fetching.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $fields = [];

    /**
     * Serialized {@see \App\Channels\ExternalParticipant}s (externalUserId /
     * email / role) — for display + audit of why the record was captured.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $participants = [];

    #[ORM\Column(length: 12, enumType: DiscoveredRecordState::class, options: ['default' => 'pending'])]
    private DiscoveredRecordState $state = DiscoveredRecordState::Pending;

    /** The Task created (import) or bound (link); null while Pending/Dismissed. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $importedEntityId = null;

    public function getChannel(): Channel { return $this->channel; }
    public function setChannel(Channel $channel): self { $this->channel = $channel; return $this; }

    public function getEntityType(): string { return $this->entityType; }
    public function setEntityType(string $type): self { $this->entityType = $type; return $this; }

    public function getExternalId(): string { return $this->externalId; }
    public function setExternalId(string $id): self { $this->externalId = $id; return $this; }

    public function getExternalUrl(): ?string { return $this->externalUrl; }
    public function setExternalUrl(?string $url): self { $this->externalUrl = $url; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    /** @return array<string, mixed> */
    public function getFields(): array { return $this->fields; }

    /** @param array<string, mixed> $fields */
    public function setFields(array $fields): self { $this->fields = $fields; return $this; }

    /** @return list<array<string, mixed>> */
    public function getParticipants(): array { return $this->participants; }

    /** @param list<array<string, mixed>> $participants */
    public function setParticipants(array $participants): self { $this->participants = $participants; return $this; }

    public function getState(): DiscoveredRecordState { return $this->state; }
    public function setState(DiscoveredRecordState $state): self { $this->state = $state; return $this; }

    public function getImportedEntityId(): ?Uuid { return $this->importedEntityId; }
    public function setImportedEntityId(?Uuid $id): self { $this->importedEntityId = $id; return $this; }
}
