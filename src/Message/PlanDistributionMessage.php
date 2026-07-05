<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction for the agent to plan how to distribute a piece of content
 * across the workspace's connected channels: build the capability catalog, ask
 * {@see \App\Service\Ai\AgentActionPlanner} for one tailored action per channel,
 * and write each as a pending agent-action {@see \App\Entity\AIRecommendation}.
 * Routed to the `ai_agents` transport (slow LLM call).
 */
final class PlanDistributionMessage
{
    public function __construct(
        private readonly Uuid $workspaceId,
        private readonly string $content,
    ) {}

    public function getWorkspaceId(): Uuid
    {
        return $this->workspaceId;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
