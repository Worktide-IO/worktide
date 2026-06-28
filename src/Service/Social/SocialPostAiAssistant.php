<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Channels\AdapterRegistry;
use App\Entity\SocialPost;
use App\Service\Llm\LlmProviderInterface;

/**
 * Generates per-network text suggestions for a {@see SocialPost}: it rewrites
 * the draft body to fit each target network's voice and character limit (pulled
 * from the {@see AdapterRegistry}). Suggestions are drafts the operator pastes
 * and edits — never auto-applied or auto-published (human-in-the-loop).
 */
final class SocialPostAiAssistant
{
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly AdapterRegistry $registry,
    ) {}

    public function isAvailable(): bool
    {
        return $this->llm->isConfigured();
    }

    /**
     * Suggest a variant for one network.
     *
     * @return array{adapterCode: string, network: string, suggestion: string, length: int, maxLength: int}
     */
    public function suggestForAdapter(SocialPost $post, string $adapterCode, ?string $tone = null): array
    {
        $adapter = $this->registry->trySocial($adapterCode);
        if ($adapter === null) {
            throw new \InvalidArgumentException(sprintf('Unknown social network "%s".', $adapterCode));
        }
        $maxLength = $adapter->maxLength();
        $network = $adapter->getLabel();

        $base = trim($post->getBody());
        $suggestion = $this->llm->complete(
            $this->buildSystemPrompt($network, $maxLength, $tone),
            $base !== '' ? $base : 'Write an engaging post.',
        );

        // Safety net: never hand back something over the network's hard cap.
        if (mb_strlen($suggestion) > $maxLength) {
            $suggestion = rtrim(mb_substr($suggestion, 0, $maxLength));
        }

        return [
            'adapterCode' => $adapterCode,
            'network' => $network,
            'suggestion' => $suggestion,
            'length' => mb_strlen($suggestion),
            'maxLength' => $maxLength,
        ];
    }

    /**
     * Suggest a variant for each distinct target network on the post.
     *
     * @return list<array{adapterCode: string, network: string, suggestion: string, length: int, maxLength: int}>
     */
    public function suggestForPost(SocialPost $post, ?string $tone = null): array
    {
        $codes = [];
        foreach ($post->getTargets() as $target) {
            $codes[$target->getChannel()->getAdapterCode()] = true;
        }

        $out = [];
        foreach (array_keys($codes) as $code) {
            if ($this->registry->trySocial($code) !== null) {
                $out[] = $this->suggestForAdapter($post, $code, $tone);
            }
        }

        return $out;
    }

    private function buildSystemPrompt(string $network, int $maxLength, ?string $tone): string
    {
        $toneLine = $tone !== null && trim($tone) !== '' ? "Tone: {$tone}.\n" : '';

        return <<<PROMPT
        You are an expert social media copywriter. Rewrite the user's draft into a single ready-to-publish {$network} post.

        Rules:
        - Hard limit: {$maxLength} characters. Stay safely under it.
        - Follow the platform's conventions (natural hashtags/mentions where they fit; concise and engaging).
        - {$toneLine}Return ONLY the post text — no preamble, no explanation, no surrounding quotes.
        PROMPT;
    }
}
