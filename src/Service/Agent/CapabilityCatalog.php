<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Channels\AdapterRegistry;
use App\Egress\EgressModule;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;

/**
 * The machine-readable catalog of outbound capabilities available to the agent
 * in a workspace — built entirely from the existing connected {@see \App\Entity\Channel}s
 * + {@see AdapterRegistry}. This is both the tool list handed to the LLM planner
 * and the source of truth the applier validates a proposed action against, so a
 * newly registered connector/channel becomes agent-usable with zero extra code.
 */
final class CapabilityCatalog
{
    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly ChannelRepository $channels,
    ) {}

    /**
     * @return list<AgentCapability>
     */
    public function forWorkspace(Workspace $workspace): array
    {
        $caps = [];

        foreach ($this->channels->findEnabledSocial($workspace) as $channel) {
            $id = $channel->getId()?->toRfc4122();
            if ($id === null) {
                continue;
            }
            $code = $channel->getAdapterCode();
            $adapter = $this->adapters->trySocial($code);
            $caps[] = new AgentCapability(
                archetype: 'social_post',
                connectorCode: $code,
                channelId: $id,
                label: $adapter?->getLabel() ?? $code,
                egressModule: EgressModule::SocialPublish->value,
                maxLength: $adapter?->maxLength(),
            );
        }

        foreach ($this->channels->findEnabledEmailOutbound($workspace) as $channel) {
            $id = $channel->getId()?->toRfc4122();
            if ($id === null) {
                continue;
            }
            $code = $channel->getAdapterCode();
            $adapter = $this->adapters->tryOutbound($code);
            $caps[] = new AgentCapability(
                archetype: 'outbound_message',
                connectorCode: $code,
                channelId: $id,
                label: $adapter?->getLabel() ?? $code,
                egressModule: EgressModule::EmailOutbound->value,
            );
        }

        return $caps;
    }
}
