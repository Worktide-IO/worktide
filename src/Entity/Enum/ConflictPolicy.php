<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Strategy applied when both Worktide AND the external system have
 * changed the same entity since the last sync. The {@see EntitySync}
 * row stores `ourVersion` and `externalUpdatedAt` so the adapter can
 * detect the dual-change condition.
 *
 *   WorktideWins   — keep Worktide's value, push to external
 *   ExternalWins   — keep external's value, overwrite local
 *   LastWriteWins  — newest `updatedAt` timestamp wins (lossy, but
 *                    works for most casual workflows)
 *   Manual         — adapter persists both versions to a holding
 *                    table and surfaces a "review" Inbox-Card; no
 *                    automatic write happens until the user picks
 *
 * Manual is the safest default for ticket-sync (Jira/Redmine — losing
 * a customer comment would be embarrassing); LastWriteWins is the
 * pragmatic default for calendar-sync (slot drift is usually fine).
 */
enum ConflictPolicy: string
{
    case WorktideWins = 'worktide_wins';
    case ExternalWins = 'external_wins';
    case LastWriteWins = 'last_write_wins';
    case Manual = 'manual';
}
