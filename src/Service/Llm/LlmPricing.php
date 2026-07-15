<?php

declare(strict_types=1);

namespace App\Service\Llm;

/**
 * Per-model list price (USD per 1M tokens, input / output), used to turn token
 * counts into a cost figure for the usage log + admin cost dashboard. List
 * prices, not contractual — good enough to compare features/models and spot
 * runaways. Unknown models cost 0 (still logged with their token counts, so a
 * missing price entry never loses the usage record — just add the price later).
 *
 * Cost is stored as integer **micro-USD** (USD × 1e6): with prices expressed per
 * 1M tokens, micro-USD = inputTokens × inPrice + outputTokens × outPrice, so no
 * precision is lost on sub-cent calls.
 */
final class LlmPricing
{
    /** @var array<string, array{0: float, 1: float}> model => [inputPer1M, outputPer1M] USD */
    private const PRICES = [
        // Anthropic (list prices, USD / 1M tokens)
        'claude-opus-4-8' => [15.0, 75.0],
        'claude-opus-4-7' => [15.0, 75.0],
        'claude-sonnet-5' => [3.0, 15.0],
        'claude-haiku-4-5-20251001' => [1.0, 5.0],
        'claude-fable-5' => [3.0, 15.0],
        // Infomaniak open-weight models — cheap flat pricing (approximate).
        'mistral3' => [0.20, 0.60],
        'llama3' => [0.20, 0.60],
        'qwen' => [0.20, 0.60],
    ];

    /** Micro-USD cost for the given token counts, 0 when the model has no price entry. */
    public function costMicros(string $model, int $inputTokens, int $outputTokens): int
    {
        $price = self::PRICES[$model] ?? null;
        if ($price === null) {
            return 0;
        }

        return (int) round($inputTokens * $price[0] + $outputTokens * $price[1]);
    }

    public function hasPrice(string $model): bool
    {
        return isset(self::PRICES[$model]);
    }
}
