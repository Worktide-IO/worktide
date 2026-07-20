<?php

declare(strict_types=1);

namespace App\Service\Llm;

/**
 * One selectable model in the {@see ModelCatalog}: a provider + its model id,
 * plus the metadata the routing UI needs to pick optimally per task — list
 * price, speed class, and the data-processing/GDPR posture ({@see ModelResidency}).
 *
 * `key` is the stable, provider-qualified identifier stored in a workspace's
 * per-feature model pin (`settings.ai.models[feature]`), e.g.
 * `anthropic:claude-haiku-4-5`. `model` is the raw id passed to the provider.
 *
 * Prices are USD per 1M tokens; since cost is stored as micro-USD (USD × 1e6),
 * `tokens × pricePer1M` yields micro-USD directly — see {@see self::costMicros()}.
 */
final class ModelDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $label,
        public readonly float $inputPer1M,
        public readonly float $outputPer1M,
        public readonly ModelResidency $residency,
        public readonly string $speed,
    ) {}

    /** Micro-USD cost for the given token counts at this model's list price. */
    public function costMicros(int $inputTokens, int $outputTokens): int
    {
        return (int) round($inputTokens * $this->inputPer1M + $outputTokens * $this->outputPer1M);
    }
}
