<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Lifecycle of an {@see \App\Entity\OutboundMessage}.
 *
 *   Queued   — created, waiting for the worker to pick up
 *   Sending  — worker has it in flight (claimed for processing)
 *   Sent     — provider acknowledged delivery
 *   Failed   — non-recoverable error (auth, malformed, hard bounce)
 *              `statusReason` carries the human-readable detail
 *   Bounced  — provider accepted but recipient bounced asynchronously
 *
 * Open / click tracking is independent of the status (separate
 * timestamp columns) so a sent message can record opens without
 * its primary state changing.
 */
enum OutboundMessageStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Bounced = 'bounced';
}
