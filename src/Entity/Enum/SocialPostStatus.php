<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Lifecycle of a {@see \App\Entity\SocialPost} — the parent of one or more
 * {@see \App\Entity\SocialPostTarget} rows (one per network).
 *
 *   Draft           — being composed; not yet submitted
 *   PendingApproval — submitted, waiting for an authorised user to approve
 *   Scheduled       — approved with a future `scheduledAt`; the publish-due
 *                     command picks it up when the time arrives
 *   Publishing      — approved/published-now; targets are being fanned out
 *   Published       — every target reached its network successfully
 *   PartiallyFailed — at least one target published, at least one failed
 *   Failed          — every target failed
 *   Canceled        — pulled before going live
 *
 * The aggregate status is recomputed from the per-target statuses after each
 * publish pass (see {@see \App\Service\Social\SocialPublisher}).
 */
enum SocialPostStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case PartiallyFailed = 'partially_failed';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
