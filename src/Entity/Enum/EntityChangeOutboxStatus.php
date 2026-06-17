<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Lifecycle of an {@see \App\Entity\EntityChangeOutbox} row.
 *
 *   Pending  — written by the Doctrine listener; worker has not picked it up
 *   Sending  — worker has claimed it for processing
 *   Sent     — all adapter fan-outs succeeded
 *   Partial  — some adapters succeeded, some failed; will retry the failed ones
 *   Failed   — all adapters failed and exhausted retries
 *   Conflict — at least one adapter reported a conflict the framework can't
 *              resolve automatically (manual-review policy in effect)
 *   Dead     — manually marked as un-recoverable (poison-pill drain)
 */
enum EntityChangeOutboxStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Partial = 'partial';
    case Failed = 'failed';
    case Conflict = 'conflict';
    case Dead = 'dead';
}
