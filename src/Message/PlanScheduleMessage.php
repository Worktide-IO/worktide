<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction to (re)plan one staff member's open tickets in one
 * workspace via the LLM scheduler, then write the resulting time slots onto the
 * tasks. Routed to `ai_agents` (LLM call). Carries only ids; the handler
 * re-loads current state, so a redelivery just produces a fresh plan.
 */
final class PlanScheduleMessage
{
    public function __construct(
        private readonly Uuid $userId,
        private readonly Uuid $workspaceId,
    ) {}

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getWorkspaceId(): Uuid
    {
        return $this->workspaceId;
    }
}
