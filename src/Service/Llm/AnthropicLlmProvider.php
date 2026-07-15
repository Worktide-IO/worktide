<?php

declare(strict_types=1);

namespace App\Service\Llm;

use Anthropic\Client;
use App\Egress\EgressGuard;
use App\Egress\EgressModule;

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
        private readonly EgressGuard $egress,
        private readonly LlmUsageRecorder $usage,
        private readonly AiUsageContext $usageContext,
        private readonly LlmBudgetGuard $budget,
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
        // Default-deny egress gate: prompt data only leaves for Anthropic when the
        // llm module is approved (EGRESS_ALLOW).
        if (!$this->egress->isAllowed(EgressModule::Llm)) {
            throw new LlmException('LLM egress not approved (module "llm").');
        }
        // Stop before the paid call once the workspace hits its monthly budget.
        $this->budget->assertWithinBudget($this->usageContext->getWorkspace());

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

        // Account for the call as soon as we have a response — a refusal / empty
        // reply below still consumed tokens.
        $this->usage->record(
            'anthropic',
            $this->model,
            (int) ($message->usage->inputTokens ?? 0),
            (int) ($message->usage->outputTokens ?? 0),
        );

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

    public function completeJson(string $system, string $user, int $maxTokens = 2048): array
    {
        $jsonSystem = $system . "\n\nReturn ONLY a single valid JSON object. No prose, no explanation, "
            . 'no Markdown code fences around it.';

        $raw = $this->complete($jsonSystem, $user, $maxTokens);
        $decoded = json_decode(self::stripFences($raw), true);

        if (!\is_array($decoded)) {
            throw new LlmException('The model did not return a valid JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Strip a leading/trailing Markdown code fence (```json … ```) the model may
     * wrap the JSON in despite instructions.
     */
    private static function stripFences(string $text): string
    {
        $trimmed = trim($text);
        if (!str_starts_with($trimmed, '```')) {
            return $trimmed;
        }
        // Drop the opening fence line (with optional language tag) and the closing fence.
        $trimmed = preg_replace('/^```[a-zA-Z0-9]*\s*\n?/', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\n?```\s*$/', '', $trimmed) ?? $trimmed;

        return trim($trimmed);
    }
}
