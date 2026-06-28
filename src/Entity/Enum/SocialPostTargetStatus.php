<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Per-network delivery state of a {@see \App\Entity\SocialPostTarget}.
 *
 *   Queued     — waiting for the publisher to attempt it
 *   Publishing — handed to the network adapter, in flight
 *   Published  — the network accepted it; `externalId` + `permalink` set
 *   Failed     — permanent failure; `errorReason` carries the detail
 *   Skipped    — deliberately not sent (e.g. validation excluded it)
 *
 * A transient failure leaves the target in {@see self::Queued} with an
 * incremented `attemptCount` so the publish-due command can retry it, until
 * the attempt cap is reached and it flips to {@see self::Failed}.
 */
enum SocialPostTargetStatus: string
{
    case Queued = 'queued';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
