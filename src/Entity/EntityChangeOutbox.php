<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Entity\Enum\EntityChangeOutboxStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\EntityChangeOutboxRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Outbox table that records every Worktide-side change to an
 * entity that's mirrored in at least one external system.
 *
 * Same pattern as {@see OutboundMessage} for mail: write the
 * intent transactionally with the entity change, let a separate
 * worker dispatch to the adapters. Decouples the API request
 * latency from the external-system reachability — a slow Jira
 * never blocks a Worktide save.
 *
 * The row is fanned out to ALL EntitySync mappings for the
 * (entityType, entityId) at processing time, not at write time.
 * That way a mapping added between change and dispatch still
 * receives the update.
 *
 * `changedFields` + `previousValues` are sparse: only the fields
 * that actually changed in this Doctrine flush. Adapters use this
 * to construct minimal PATCH requests and for conflict detection
 * (if previousValue !== externalCurrentValue → conflict).
 *
 * Re-entry guard: the {@see \App\Channels\SyncReentryGuard} flag
 * is set while inbound sync writes are being applied; the
 * listener that creates these rows skips writes during that
 * window so an inbound update doesn't bounce right back to the
 * source.
 */
#[ORM\Entity(repositoryClass: EntityChangeOutboxRepository::class)]
#[ORM\Table(name: 'entity_change_outbox')]
#[ORM\Index(name: 'entity_outbox_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'entity_outbox_status_idx', columns: ['status'])]
#[ORM\Index(name: 'entity_outbox_claimable_idx', columns: ['status', 'next_attempt_at'])]
#[ORM\Index(name: 'entity_outbox_entity_idx', columns: ['entity_type', 'entity_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'EntityChangeOutbox',
    operations: [
        new GetCollection(),
        new Get(),
        new Patch(),
        new Delete(),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'status' => 'exact',
    'entityType' => 'exact',
    'entityId' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'nextAttemptAt'])]
class EntityChangeOutbox
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 40)]
    private string $entityType;

    #[ORM\Column(type: 'uuid')]
    private Uuid $entityId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $changedFields = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $previousValues = [];

    #[ORM\Column]
    private bool $isDelete = false;

    #[ORM\Column(length: 16, enumType: EntityChangeOutboxStatus::class, options: ['default' => 'pending'])]
    private EntityChangeOutboxStatus $status = EntityChangeOutboxStatus::Pending;

    #[ORM\Column(type: 'integer')]
    private int $attemptCount = 0;

    /**
     * Earliest time the worker is allowed to claim this row again.
     * Set to "now" on enqueue + on each retry; advances on failure
     * by an exponential backoff so a broken adapter doesn't starve
     * the worker.
     */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $nextAttemptAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    /**
     * Per-mapping outcome — JSON map `entitySyncId → {result, etag,
     * externalUpdatedAt, error}` so the worker can do partial
     * retries (only the failed mappings) without re-pushing to the
     * adapters that already succeeded.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $perMappingState = [];

    public function __construct()
    {
        $this->nextAttemptAt = new \DateTimeImmutable();
    }

    public function getEntityType(): string { return $this->entityType; }
    public function setEntityType(string $t): self { $this->entityType = $t; return $this; }

    public function getEntityId(): Uuid { return $this->entityId; }
    public function setEntityId(Uuid $id): self { $this->entityId = $id; return $this; }

    /** @return array<string, mixed> */
    public function getChangedFields(): array { return $this->changedFields; }
    /** @param array<string, mixed> $f */
    public function setChangedFields(array $f): self { $this->changedFields = $f; return $this; }

    /** @return array<string, mixed> */
    public function getPreviousValues(): array { return $this->previousValues; }
    /** @param array<string, mixed> $v */
    public function setPreviousValues(array $v): self { $this->previousValues = $v; return $this; }

    public function isDelete(): bool { return $this->isDelete; }
    public function setIsDelete(bool $v): self { $this->isDelete = $v; return $this; }

    public function getStatus(): EntityChangeOutboxStatus { return $this->status; }
    public function setStatus(EntityChangeOutboxStatus $s): self { $this->status = $s; return $this; }

    public function getAttemptCount(): int { return $this->attemptCount; }
    public function setAttemptCount(int $n): self { $this->attemptCount = $n; return $this; }
    public function incrementAttempts(): self { $this->attemptCount++; return $this; }

    public function getNextAttemptAt(): \DateTimeImmutable { return $this->nextAttemptAt; }
    public function setNextAttemptAt(\DateTimeImmutable $t): self { $this->nextAttemptAt = $t; return $this; }

    public function getLastError(): ?string { return $this->lastError; }
    public function setLastError(?string $e): self { $this->lastError = $e; return $this; }

    public function getProcessedAt(): ?\DateTimeImmutable { return $this->processedAt; }
    public function setProcessedAt(?\DateTimeImmutable $t): self { $this->processedAt = $t; return $this; }

    /** @return array<string, mixed> */
    public function getPerMappingState(): array { return $this->perMappingState; }
    /** @param array<string, mixed> $s */
    public function setPerMappingState(array $s): self { $this->perMappingState = $s; return $this; }
}
