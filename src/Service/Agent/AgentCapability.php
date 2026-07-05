<?php

declare(strict_types=1);

namespace App\Service\Agent;

/**
 * One outbound capability the agent can propose against: a connected channel,
 * reduced to what the LLM planner needs to choose it and what the applier needs
 * to execute it. `archetype` is the small fixed dispatch key (social_post |
 * outbound_message); `connectorCode` is the channel's adapterCode (unbounded).
 */
final readonly class AgentCapability
{
    public function __construct(
        public string $archetype,
        public string $connectorCode,
        public string $channelId,
        public string $label,
        public string $egressModule,
        public ?int $maxLength = null,
    ) {}

    /**
     * Compact shape shown to the LLM planner (the "tool catalog").
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'archetype' => $this->archetype,
            'connectorCode' => $this->connectorCode,
            'channelId' => $this->channelId,
            'label' => $this->label,
            'maxLength' => $this->maxLength,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
