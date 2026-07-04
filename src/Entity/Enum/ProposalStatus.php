<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Customer-review lifecycle of a {@see \App\Entity\ProjectProposal} — the
 * portal "Ideen-Pitch" tabs (Neu → In Prüfung → Angenommen/Abgelehnt).
 */
enum ProposalStatus: string
{
    case New = 'new';
    case InReview = 'in_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
