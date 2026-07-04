<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/** Kind of event in a lead's append-only activity/state history. */
enum LeadActivityType: string
{
    case Discovered = 'discovered';
    case Enriched = 'enriched';
    case StageChange = 'stage_change';
    case EmailSent = 'email_sent';
    case Reply = 'reply';
    case ForumPost = 'forum_post';
    case Call = 'call';
    case Note = 'note';
}
