<?php

declare(strict_types=1);

namespace App\Channels;

use Symfony\Component\Uid\Uuid;

/**
 * Framework → adapter notification that a Worktide-side entity
 * changed and should be pushed to the external system.
 *
 * Emitted by the Doctrine listener that watches EntitySync-mapped
 * entities; processed by a Symfony Messenger handler that fans the
 * change out to every adapter the entity is mapped through.
 *
 * `changedFields` is sparse — only the keys that actually changed.
 * Adapters use this to send minimal PATCH requests (Jira's REST
 * encourages this) and skip full re-pushes when only an
 * inconsequential field (e.g. `updatedAt`) moved.
 */
final class EntityChange
{
    /**
     * @param array<string, mixed> $changedFields  field → newValue (Worktide-shape)
     * @param array<string, mixed> $previousValues field → oldValue, for conflict detection
     */
    public function __construct(
        public readonly string $entityType,
        public readonly Uuid $entityId,
        public readonly array $changedFields,
        public readonly array $previousValues = [],
        public readonly bool $isDelete = false,
    ) {}
}
