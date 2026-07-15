<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction to run AI effort estimation for one Task and persist a
 * Pending {@see \App\Entity\AIRecommendation} of kind
 * {@see \App\Entity\Enum\RecommendationKind::Estimate}.
 *
 * Routed to the `ai_agents` transport (like {@see TriageTicketMessage}) so the
 * slow LLM call can't starve the fast `async` queue. Carries only the task id;
 * the handler re-loads the row, so a redelivery acts on current state and
 * simply produces a fresh estimate (superseding any still-pending one).
 */
final class EstimateTaskMessage
{
    public function __construct(
        private readonly Uuid $taskId,
    ) {}

    public function getTaskId(): Uuid
    {
        return $this->taskId;
    }
}
