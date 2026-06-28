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
     * Produce a single completion for the given system + user prompt.
     *
     * @throws LlmException when not configured, on a transport/API failure, on a
     *                      model refusal, or when the response carries no text
     */
    public function complete(string $system, string $user, int $maxTokens = 4096): string;
}
