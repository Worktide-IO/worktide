<?php

declare(strict_types=1);

namespace App\Service\Llm;

/**
 * Selects the active {@see LlmProviderInterface} implementation from the
 * `LLM_PROVIDER` env at container build time, so the whole app depends on the
 * interface and the provider choice lives in ONE place.
 *
 * Default is `anthropic` (unchanged behaviour). Set `LLM_PROVIDER=infomaniak`
 * to route all AI through the Swiss/EU-hosted, OpenAI-compatible Infomaniak
 * provider. Unknown/empty values fall back to Anthropic so a typo can't leave
 * the app without a provider.
 */
final class LlmProviderFactory
{
    public function __construct(
        private readonly AnthropicLlmProvider $anthropic,
        private readonly InfomaniakLlmProvider $infomaniak,
        private readonly ?string $providerName = null,
    ) {}

    public function create(): LlmProviderInterface
    {
        return match (strtolower(trim((string) $this->providerName))) {
            'infomaniak' => $this->infomaniak,
            default => $this->anthropic,
        };
    }
}
