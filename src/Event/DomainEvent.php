<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\User;
use App\Entity\Workspace;
use Symfony\Component\Uid\Uuid;

/**
 * Marker base class for all domain events.
 *
 * Implementations are dispatched on the Messenger bus and persisted to the
 * domain_events log via DomainEventPersister.
 *
 * Subclasses provide:
 * - getName(): a stable, public event name like "task.assigned" (NEVER rename
 *   after consumers subscribe to it).
 * - getAggregateType()/getAggregateId(): the entity the event is about.
 * - getWorkspace()/getActor(): tenancy + audit context.
 * - getPayload(): JSON-serialisable data describing the change.
 */
abstract class DomainEvent
{
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        protected readonly string $aggregateType,
        protected readonly ?Uuid $aggregateId,
        protected readonly ?Workspace $workspace = null,
        protected readonly ?User $actor = null,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    abstract public function getName(): string;

    /** @return array<string, mixed> */
    abstract public function getPayload(): array;

    public function getAggregateType(): string
    {
        return $this->aggregateType;
    }

    public function getAggregateId(): ?Uuid
    {
        return $this->aggregateId;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
