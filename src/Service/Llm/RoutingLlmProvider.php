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

    public function complete(string $system, string $user, int $maxTokens = 4096, ?string $model = null): string
    {
        $call = $this->resolve();

        try {
            return $call->provider->complete($system, $user, $maxTokens, $model ?? $call->model);
        } catch (LlmException $e) {
            if ($call->fallbackProvider === null) {
                throw $e;
            }

            return $call->fallbackProvider->complete($system, $user, $maxTokens, $model ?? $call->fallbackModel);
        }
    }

    public function completeJson(string $system, string $user, int $maxTokens = 2048, ?string $model = null): array
    {
        $call = $this->resolve();

        try {
            return $call->provider->completeJson($system, $user, $maxTokens, $model ?? $call->model);
        } catch (LlmException $e) {
            if ($call->fallbackProvider === null) {
                throw $e;
            }

            return $call->fallbackProvider->completeJson($system, $user, $maxTokens, $model ?? $call->fallbackModel);
        }
    }

    public function getModel(): string
    {
        // Provenance for a just-produced suggestion: the model the current context
        // routes to (its explicit pin, else the primary provider's default).
        $call = $this->resolve();

        return $call->model ?? $call->provider->getModel();
    }

    private function resolve(): RoutedCall
    {
        return $this->router->route($this->usageContext->getFeature(), $this->usageContext->getWorkspace());
    }
}
