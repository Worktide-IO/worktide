<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Lifecycle of a {@see \App\Entity\DiscoveredExternalRecord} — an unmapped
 * external ticket that involves a workspace person and is waiting for an
 * operator decision.
 *
 *   Pending   — discovered, not yet acted on.
 *   Imported  — a new local Task was created from it (+ EntitySync mapping).
 *   Linked    — bound to an existing local Task (+ EntitySync mapping).
 *   Dismissed — operator marked it irrelevant; kept for audit / re-dedup.
 */
enum DiscoveredRecordState: string
{
    case Pending = 'pending';
    case Imported = 'imported';
    case Linked = 'linked';
    case Dismissed = 'dismissed';
}
