<?php

declare(strict_types=1);

namespace App\Service\Llm;

/**
 * Minimal text-completion seam over an LLM provider. Kept deliberately small so
 * features (social copy suggestions today, inbound classification later) depend
 * on this interface, not on a concrete SDK — and so it's trivial to stub in tests.
 */
interface LlmProviderInterface
{
    /** Whether a credential is present; lets callers return a clean 503 instead of failing mid-request. */
    public function isConfigured(): bool;

    /**
     * Produce a single completion for the given system + user prompt. `$model`
     * overrides the provider's configured default for this one call (used by the
     * per-task-type router to pin a specific catalog model); null = default.
     *
     * @throws LlmException when not configured, on a transport/API failure, on a
     *                      model refusal, or when the response carries no text
     */
    public function complete(string $system, string $user, int $maxTokens = 4096, ?string $model = null): string;

    /**
     * Like {@see complete()} but for structured output: instructs the model to
     * emit JSON, strips any Markdown code fences, and decodes to an associative
     * array. The provider has no native tool-calling seam, so this is prompt-driven.
     *
     * @return array<string, mixed>
     *
     * @throws LlmException when not configured, on failure/refusal, or when the
     *                      response is not valid JSON object
     */
    public function completeJson(string $system, string $user, int $maxTokens = 2048, ?string $model = null): array;

    /** The model identifier this provider will use — for provenance on stored suggestions. */
    public function getModel(): string;
}
