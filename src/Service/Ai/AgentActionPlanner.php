<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Service\Agent\AgentCapability;
use App\Service\Llm\LlmProviderInterface;

/**
 * The agent's "work out the recommendations yourself" step for content
 * distribution: given a piece of content and the machine-readable capability
 * catalog (which channels/connectors are connected), the LLM proposes one
 * tailored outbound action per suitable channel. Suggestions only — each becomes
 * a pending {@see \App\Entity\AIRecommendation} a human accepts, which then flows
 * through the normal egress-gated draft→publish pipeline.
 */
final class AgentActionPlanner
{
    private const MAX_ACTIONS = 12;

    public function __construct(
        private readonly LlmProviderInterface $llm,
    ) {}

    public function isAvailable(): bool
    {
        return $this->llm->isConfigured();
    }

    public function getModel(): string
    {
        return $this->llm->getModel();
    }

    /**
     * @param AgentCapability[] $capabilities
     *
     * @return list<array<string, mixed>> validated actions:
     *   {archetype, connectorCode, channelId, payload:{body[,recipient,subject]}, rationale}
     */
    public function planDistribution(string $content, array $capabilities): array
    {
        if ($capabilities === [] || trim($content) === '') {
            return [];
        }
        /** @var array<string, AgentCapability> $byChannel */
        $byChannel = [];
        foreach ($capabilities as $c) {
            $byChannel[$c->channelId] = $c;
        }

        $raw = $this->llm->completeJson($this->systemPrompt(), $this->buildContext($content, $capabilities), 8192);

        $out = [];
        foreach ($this->asList($raw['actions'] ?? null) as $a) {
            if (!\is_array($a)) {
                continue;
            }
            $channelId = \is_string($a['channelId'] ?? null) ? $a['channelId'] : '';
            $cap = $byChannel[$channelId] ?? null;
            if ($cap === null) {
                continue; // drop hallucinated / non-catalog channel
            }
            $payload = \is_array($a['payload'] ?? null) ? $a['payload'] : [];
            $body = \is_string($payload['body'] ?? null) ? trim($payload['body']) : '';
            if ($body === '') {
                continue;
            }
            if ($cap->maxLength !== null && $cap->maxLength > 0) {
                $body = mb_substr($body, 0, $cap->maxLength);
            }

            $actionPayload = ['body' => $body];
            if ($cap->archetype === 'outbound_message') {
                $recipient = \is_string($payload['recipient'] ?? null) ? trim($payload['recipient']) : '';
                if ($recipient === '') {
                    continue; // cannot send an email without a recipient
                }
                $actionPayload['recipient'] = $recipient;
                $subject = \is_string($payload['subject'] ?? null) ? trim($payload['subject']) : '';
                if ($subject !== '') {
                    $actionPayload['subject'] = mb_substr($subject, 0, 200);
                }
            }

            $out[] = [
                'archetype' => $cap->archetype,
                'connectorCode' => $cap->connectorCode,
                'channelId' => $cap->channelId,
                'payload' => $actionPayload,
                'rationale' => \is_string($a['rationale'] ?? null) ? mb_substr(trim($a['rationale']), 0, 500) : null,
            ];
            if (\count($out) >= self::MAX_ACTIONS) {
                break;
            }
        }

        return $out;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You are a distribution assistant. Given a piece of content and a catalog of the
            workspace's connected outbound channels (each with an id, label, archetype and
            optional maxLength), propose how to distribute the content: at most ONE action per
            suitable channel, with the message body TAILORED to that channel's audience and kept
            within its maxLength. Skip channels that don't fit. Use ONLY channelIds from the
            catalog — never invent one. For an "outbound_message" archetype include a recipient
            (and optional subject); for "social_post" only a body. Write in the content's language
            (default German). Reply with STRICT JSON:
            {"actions":[{"channelId":str,"payload":{"body":str,"recipient":str?,"subject":str?},"rationale":str}]}
            Base every action only on the given content and catalog — invent no facts.
            PROMPT;
    }

    /**
     * @param AgentCapability[] $capabilities
     */
    private function buildContext(string $content, array $capabilities): string
    {
        $catalog = json_encode(
            array_map(static fn (AgentCapability $c): array => $c->toArray(), $capabilities),
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        );

        return "# Content\n" . mb_substr(trim($content), 0, 6000)
            . "\n\n# Channel catalog\n" . ($catalog !== false ? $catalog : '[]');
    }

    /**
     * @return list<mixed>
     */
    private function asList(mixed $v): array
    {
        return \is_array($v) ? array_values($v) : [];
    }
}
