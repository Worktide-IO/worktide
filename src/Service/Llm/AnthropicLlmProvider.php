<?php

declare(strict_types=1);

namespace App\Service\Llm;

use Anthropic\Client;

/**
 * {@see LlmProviderInterface} backed by Anthropic's official PHP SDK.
 *
 * Defaults to Claude Opus 4.8 (`claude-opus-4-8`); the model is env-overridable.
 * Thinking is left off (omitted) — short marketing copy doesn't need it. A
 * `refusal` stop reason or an empty response is surfaced as an {@see LlmException}
 * so callers can present a clean error.
 */
final class AnthropicLlmProvider implements LlmProviderInterface
{
    private const DEFAULT_MODEL = 'claude-opus-4-8';

    private readonly string $apiKey;
    private readonly string $model;

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
    ) {
        $this->apiKey = (string) $apiKey;
        $this->model = ($model !== null && $model !== '') ? $model : self::DEFAULT_MODEL;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function complete(string $system, string $user, int $maxTokens = 4096): string
    {
        if (!$this->isConfigured()) {
            throw new LlmException('ANTHROPIC_API_KEY is not configured.');
        }

        $client = new Client(apiKey: $this->apiKey);

        try {
            $message = $client->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $user]],
                model: $this->model,
                system: $system,
            );
        } catch (\Throwable $e) {
            throw new LlmException('LLM request failed: ' . $e->getMessage(), previous: $e);
        }

        $stopReason = $message->stopReason;
        $stopValue = $stopReason instanceof \BackedEnum ? (string) $stopReason->value : (string) $stopReason;
        if ($stopValue === 'refusal') {
            throw new LlmException('The model declined to generate this content.');
        }

        $text = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }
        }
        $text = trim($text);
        if ($text === '') {
            throw new LlmException('The model returned no text.');
        }

        return $text;
    }
}
