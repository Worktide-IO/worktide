<?php

declare(strict_types=1);

namespace App\Service\Llm;

/**
 * The provider chain the {@see LlmRouter} resolves a call to: the primary
 * provider + optional per-call model override, plus an optional fallback the
 * {@see RoutingLlmProvider} tries on an {@see LlmException} (the LocalFallbackCloud
 * tier). A null model means "use the provider's configured default".
 */
final class RoutedCall
{
    public function __construct(
        public readonly LlmProviderInterface $provider,
        public readonly ?string $model = null,
        public readonly ?LlmProviderInterface $fallbackProvider = null,
        public readonly ?string $fallbackModel = null,
    ) {}
}
