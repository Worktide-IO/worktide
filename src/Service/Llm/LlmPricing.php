<?php

declare(strict_types=1);

namespace App\Service\Llm;

/**
 * Turns token counts into a cost figure for the usage log + admin cost
 * dashboard. Prices now live in the {@see ModelCatalog} (single source); this is
 * a thin adapter keyed by the raw provider model id the usage recorder records.
 *
 * Unknown models cost 0 (still logged with their token counts, so a missing
 * catalog entry never loses the usage record — e.g. the deployment-bound local
 * Ollama model is intentionally not in the catalog and therefore free). Cost is
 * integer micro-USD (USD × 1e6); with catalog prices per 1M tokens, no precision
 * is lost on sub-cent calls.
 */
final class LlmPricing
{
    public function __construct(
        private readonly ModelCatalog $catalog,
    ) {}

    /** Micro-USD cost for the given token counts, 0 when the model has no catalog entry. */
    public function costMicros(string $model, int $inputTokens, int $outputTokens): int
    {
        return $this->catalog->byModelId($model)?->costMicros($inputTokens, $outputTokens) ?? 0;
    }

    public function hasPrice(string $model): bool
    {
        return $this->catalog->byModelId($model) !== null;
    }
}
