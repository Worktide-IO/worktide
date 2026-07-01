<?php

declare(strict_types=1);

namespace App\Message;

use App\Entity\Enum\RecommendationTarget;
use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction to run AI triage for one ticket (Task or Conversation) and
 * persist a Pending {@see \App\Entity\AIRecommendation}.
 *
 * Routed to the dedicated `ai_agents` transport so the slow LLM call can't
 * starve the fast `async` queue. Carries only the target type + id; the handler
 * re-loads the row, so a redelivery acts on current state and simply produces a
 * fresh suggestion (superseding any still-pending one).
 */
final class TriageTicketMessage
{
    public function __construct(
        private readonly RecommendationTarget $target,
        private readonly Uuid $targetId,
    ) {}

    public function getTarget(): RecommendationTarget
    {
        return $this->target;
    }

    public function getTargetId(): Uuid
    {
        return $this->targetId;
    }
}
