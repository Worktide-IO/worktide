<?php

declare(strict_types=1);

namespace App\Channels;

/**
 * Thread-local marker that pauses the outbox-recording listener
 * while an inbound sync is writing back into Worktide.
 *
 * Without this, the flow looks like:
 *
 *   1. Jira webhook → inbound adapter updates Task X
 *   2. Doctrine flush → EntitySyncRecordingListener sees Task X
 *      changed → enqueues an outbox row
 *   3. Worker picks up the row → calls adapter.pushEntity()
 *   4. Adapter pushes the unchanged value back to Jira
 *   5. Jira fires another webhook for the no-op write → loop
 *
 * The guard is set at the start of inbound application and
 * cleared at the end (try/finally). The listener reads it on
 * every flush and skips outbox writes while the guard is held.
 *
 * Implemented as a static int counter so nested guards (rare,
 * but possible when two adapters both reactor to the same flush)
 * don't release prematurely.
 */
final class SyncReentryGuard
{
    private int $depth = 0;

    public function enter(): void
    {
        $this->depth++;
    }

    public function leave(): void
    {
        if ($this->depth > 0) {
            $this->depth--;
        }
    }

    public function isActive(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Wrap a callable with enter/leave so the listener pause is
     * guaranteed to be released even when the callable throws.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function run(callable $fn): mixed
    {
        $this->enter();
        try {
            return $fn();
        } finally {
            $this->leave();
        }
    }
}
