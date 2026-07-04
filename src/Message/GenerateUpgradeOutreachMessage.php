<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction to draft an upgrade/upsell outreach email for one
 * {@see \App\Entity\Customer} and persist a Pending
 * {@see \App\Entity\AIRecommendation} ({@see \App\Entity\Enum\RecommendationKind::CustomerUpgradeOutreach}).
 *
 * Routed to the dedicated `ai_agents` transport so the slow LLM call can't
 * starve the fast `async` queue. Carries only the customer id; the handler
 * re-loads current state and supersedes any still-pending suggestion.
 */
final class GenerateUpgradeOutreachMessage
{
    public function __construct(
        private readonly Uuid $customerId,
    ) {}

    public function getCustomerId(): Uuid
    {
        return $this->customerId;
    }
}
