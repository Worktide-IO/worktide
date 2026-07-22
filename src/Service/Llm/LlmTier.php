<?php

declare(strict_types=1);

namespace App\Service\Llm;

/**
 * Where a given AI task-type is served. This is the routing target the
 * {@see LlmRouter} resolves a feature to — NOT a provider name: "cloud" is
 * whichever paid provider {@see LlmProviderFactory} selects (Anthropic default,
 * Infomaniak opt-in), "local" is the on-infra {@see OllamaLlmProvider}.
 *
 * The split follows the cost/quality trade-off: volume-heavy, reasoning-light
 * work (classification, extraction, tagging, summarisation) goes local; agentic
 * tool-chains and outbound drafting stay on the cloud model. A privacy workspace
 * (`settings.ai.forceLocal`) overrides all of this to Local, for data residency.
 */
enum LlmTier: string
{
    /** On-infra model. Prompt never leaves the infrastructure. */
    case Local = 'local';

    /** The env-selected paid provider (Anthropic / Infomaniak). */
    case Cloud = 'cloud';

    /** Try local first; on any {@see LlmException} fall back to the cloud model. */
    case LocalFallbackCloud = 'local_fallback_cloud';

    /**
     * Auto-pick the cheapest available catalog model that meets the task's
     * quality floor (so agentic tasks aren't dropped onto a tiny model).
     * Resolved by {@see LlmRouter}; forceLocal + an explicit model pin still win.
     */
    case Cheapest = 'cheapest';

    /** Parse a stored/API value, tolerating unknown strings by returning null. */
    public static function tryFromLoose(mixed $value): ?self
    {
        return \is_string($value) ? self::tryFrom(trim($value)) : null;
    }
}
