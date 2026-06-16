<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Processing state of an {@see \App\Entity\InboundEvent}.
 *
 *   Pending   — just ingested, AI/automations haven't classified yet.
 *   Processed — AI ran, recommendations (if any) have been emitted.
 *   Dismissed — operator (or AI's "no action" verdict) marked it
 *               irrelevant; stays in the table for audit + training.
 */
enum InboundEventState: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Dismissed = 'dismissed';
}
