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
    case Estimate = 'estimate';
    case TicketFromConversation = 'ticket_from_conversation';
    case MarketingSocialDraft = 'marketing_social_draft';
    case CustomerUpgradeOutreach = 'customer_upgrade_outreach';
    case ResearchSuggestion = 'research_suggestion';
    case AgentAction = 'agent_action';
    case ProductSuggestion = 'product_suggestion';
}
