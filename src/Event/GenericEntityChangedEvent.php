<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\User;
use App\Entity\Workspace;
use Symfony\Component\Uid\Uuid;

/**
 * Generic Created/Updated/Deleted event emitted by the Doctrine listener.
 *
 * Named like "{aggregateType}.{action}" — e.g. "task.created", "project.updated".
 * Typed semantic events (e.g. TaskAssignedEvent) extend DomainEvent directly.
 */
final class GenericEntityChangedEvent extends DomainEvent
{
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DELETED = 'deleted';

    /** @param array<string, mixed> $payload */
    public function __construct(
        string $aggregateType,
        ?Uuid $aggregateId,
        private readonly string $action,
        private readonly array $payload,
        ?Workspace $workspace = null,
        ?User $actor = null,
    ) {
        parent::__construct($aggregateType, $aggregateId, $workspace, $actor);
    }

    public function getName(): string
    {
        return strtolower($this->aggregateType) . '.' . $this->action;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
