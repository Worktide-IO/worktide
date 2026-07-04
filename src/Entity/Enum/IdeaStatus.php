<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Lifecycle of an {@see \App\Entity\Idea} on the customer idea board.
 * Aligns with the pitch/vorschlag flow (neu → in Prüfung → angenommen/
 * abgelehnt) and adds `Done` for shipped ideas.
 */
enum IdeaStatus: string
{
    case Proposed = 'proposed';
    case UnderReview = 'under_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Done = 'done';
}
