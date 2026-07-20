<?php

declare(strict_types=1);

namespace App\Tests\Service\Llm;

use App\Service\Llm\LlmPricing;
use App\Service\Llm\ModelCatalog;
use App\Service\Llm\ModelResidency;
use PHPUnit\Framework\TestCase;

final class ModelCatalogTest extends TestCase
{
    public function testKeysAreUniqueAndResolvable(): void
    {
        $catalog = new ModelCatalog();
        $keys = array_map(static fn ($m): string => $m->key, $catalog->all());

        self::assertSame($keys, array_unique($keys), 'catalog keys must be unique');
        foreach ($keys as $key) {
            self::assertNotNull($catalog->get($key));
        }
    }

    public function testByModelIdResolvesRawProviderModel(): void
    {
        $catalog = new ModelCatalog();

        self::assertSame('anthropic:claude-opus-4-8', $catalog->get('anthropic:claude-opus-4-8')?->key);
        self::assertSame('claude-opus-4-8', $catalog->byModelId('claude-opus-4-8')?->model);
        self::assertNull($catalog->byModelId('does-not-exist'));
        self::assertNull($catalog->get('does-not-exist'));
    }

    public function testResidencyDrivesTheEuSignal(): void
    {
        $catalog = new ModelCatalog();

        self::assertSame(ModelResidency::Us, $catalog->get('anthropic:claude-opus-4-8')?->residency);
        self::assertFalse($catalog->get('anthropic:claude-opus-4-8')?->residency->staysInEu());
        self::assertSame(ModelResidency::Eu, $catalog->get('infomaniak:mistral3')?->residency);
        self::assertTrue($catalog->get('infomaniak:mistral3')?->residency->staysInEu());
    }

    public function testPricingDelegatesToCatalogInMicroUsd(): void
    {
        $pricing = new LlmPricing(new ModelCatalog());

        // Opus at $15 / 1M input → 1M tokens = $15 = 15_000_000 micro-USD.
        self::assertSame(15_000_000, $pricing->costMicros('claude-opus-4-8', 1_000_000, 0));
        // $75 / 1M output.
        self::assertSame(75_000_000, $pricing->costMicros('claude-opus-4-8', 0, 1_000_000));
        self::assertTrue($pricing->hasPrice('claude-opus-4-8'));
        // Unknown / local model → free, still loggable.
        self::assertSame(0, $pricing->costMicros('some-local-llama', 1_000_000, 1_000_000));
        self::assertFalse($pricing->hasPrice('some-local-llama'));
    }
}
