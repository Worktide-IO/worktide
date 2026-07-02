<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Instruction to (re)index or remove one entity in the search index. Dispatched
 * by {@see \App\EventListener\SearchIndexingListener} after a flush commits,
 * routed to the `search` transport. Carries only type + id (+ delete flag); the
 * handler re-loads the entity so it always indexes current state.
 */
final class SyncSearchIndexMessage
{
    public function __construct(
        private readonly string $type,
        private readonly Uuid $id,
        private readonly bool $delete = false,
    ) {}

    public function getType(): string
    {
        return $this->type;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function isDelete(): bool
    {
        return $this->delete;
    }
}
