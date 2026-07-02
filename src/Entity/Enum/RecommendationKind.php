<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * The kind of AI assistance a recommendation represents. Triage is the first
 * (Phase D Schicht 1); Estimate / Breakdown / Reply follow and reuse the same
 * {@see \App\Entity\AIRecommendation} envelope.
 */
enum RecommendationKind: string
{
    case Triage = 'triage';
    case TicketFromConversation = 'ticket_from_conversation';
}
