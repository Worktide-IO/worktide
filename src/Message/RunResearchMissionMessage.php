<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction to run one discovery pass for a {@see \App\Entity\ResearchMission}:
 * fan the brief out to the external-search adapters, score the hits into leads
 * and persist the new ones. Routed to the `ai_agents` transport (slow search +
 * LLM). Carries only the mission id; the handler re-loads state and resumes.
 */
final class RunResearchMissionMessage
{
    public function __construct(
        private readonly Uuid $missionId,
    ) {}

    public function getMissionId(): Uuid
    {
        return $this->missionId;
    }
}
