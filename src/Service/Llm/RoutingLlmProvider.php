<?php

declare(strict_types=1);

namespace App\Service\Llm;

/**
 * The {@see LlmProviderInterface} the whole app depends on — a thin decorator
 * that routes each call to a concrete provider PER TASK-TYPE instead of a single
 * globally-selected one. It reads the ambient (feature, workspace) attribution
 * the assistants already set on {@see AiUsageContext} right before calling, asks
 * the {@see LlmRouter} for the provider chain, and delegates.
 *
 * A {@see LlmTier::LocalFallbackCloud} decision yields a (local, cloud) chain:
 * the local model is tried first and, on any {@see LlmException} (transport,
 * refusal, empty response), the call is retried on the cloud model. A privacy
 * workspace (`settings.ai.forceLocal`) gets a local-only chain with no fallback,
 * so a failure surfaces rather than silently leaving the infrastructure.
 */
final class RoutingLlmProvider implements LlmProviderInterface
{
    public function __construct(
        private readonly LlmRouter $router,
        private readonly AiUsageContext $usageContext,
    ) {}

    public function isConfigured(): bool
    {
        // Either path can serve a call, depending on how a given feature routes.
        return $this->router->isAnyConfigured();
    }

    public function complete(string $system, string $user, int $maxTokens = 4096): string
    {
        [$primary, $fallback] = $this->resolve();

        try {
            return $primary->complete($system, $user, $maxTokens);
        } catch (LlmException $e) {
            if ($fallback === null) {
                throw $e;
            }

            return $fallback->complete($system, $user, $maxTokens);
        }
    }

    public function completeJson(string $system, string $user, int $maxTokens = 2048): array
    {
        [$primary, $fallback] = $this->resolve();

        try {
            return $primary->completeJson($system, $user, $maxTokens);
        } catch (LlmException $e) {
            if ($fallback === null) {
                throw $e;
            }

            return $fallback->completeJson($system, $user, $maxTokens);
        }
    }

    public function getModel(): string
    {
        // Provenance for a just-produced suggestion: the primary the current
        // context routes to (a fallback would be the rare exception).
        [$primary] = $this->resolve();

        return $primary->getModel();
    }

    /**
     * @return array{0: LlmProviderInterface, 1: ?LlmProviderInterface}
     */
    private function resolve(): array
    {
        return $this->router->route($this->usageContext->getFeature(), $this->usageContext->getWorkspace());
    }
}
