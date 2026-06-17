<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Direction-of-truth for an EntitySync mapping. Picked per channel
 * at setup time and stored on each mapping row so different entities
 * can use different directions even on the same channel (rare but
 * legal — e.g. a calendar adapter that pushes TimeEntries but only
 * pulls events).
 *
 *   Bidirectional — both sides may change; conflict-policy applies
 *   Inbound       — external system owns the truth; Worktide updates
 *                   are NOT pushed back
 *   Outbound      — Worktide owns the truth; external system is a
 *                   downstream mirror that we write but don't read
 *                   for inbound changes
 *   Disabled      — paused without losing the mapping (debugging /
 *                   migration safeguard)
 */
enum SyncMode: string
{
    case Bidirectional = 'bidirectional';
    case Inbound = 'inbound';
    case Outbound = 'outbound';
    case Disabled = 'disabled';
}
