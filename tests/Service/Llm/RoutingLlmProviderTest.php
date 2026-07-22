<?php

declare(strict_types=1);

namespace App\Tests\Service\Llm;

use App\Service\Llm\AiUsageContext;
use App\Service\Llm\LlmException;
use App\Service\Llm\LlmProviderInterface;
use App\Service\Llm\LlmRouter;
use App\Service\Llm\RoutedCall;
use App\Service\Llm\RoutingLlmProvider;
use App\Tests\Support\RecordingLlmProvider;
use PHPUnit\Framework\TestCase;

/**
 * The decorator that turns a router decision ({@see RoutedCall}) into a delegated
 * call: it must use the primary provider (with the pinned per-call model), fall
 * back to the secondary on an {@see LlmException}, and re-throw when there is no
 * fallback.
 */
final class RoutingLlmProviderTest extends TestCase
{
    public function testDelegatesToPrimaryWithPinnedModel(): void
    {
        $primary = new RecordingLlmProvider(json: ['ok' => true], text: 'primary-text', model: 'primary');
        $decorator = $this->decorator(new RoutedCall($primary, 'claude-haiku-4-5-20251001'));

        self::assertSame('primary-text', $decorator->complete('sys', 'usr'));
        self::assertSame(['ok' => true], $decorator->completeJson('sys', 'usr'));
        // getModel reflects the pinned model, not the provider default.
        self::assertSame('claude-haiku-4-5-20251001', $decorator->getModel());
        // The pinned model was threaded through to the provider call.
        self::assertSame('claude-haiku-4-5-20251001', $primary->calls[0]['model']);
    }

    public function testGetModelFallsBackToProviderDefaultWhenNoPin(): void
    {
        $primary = new RecordingLlmProvider(model: 'provider-default');
        $decorator = $this->decorator(new RoutedCall($primary));

        self::assertSame('provider-default', $decorator->getModel());
    }

    public function testFallsBackToSecondaryWhenPrimaryThrows(): void
    {
        $fallback = new RecordingLlmProvider(json: ['from' => 'fallback'], text: 'fallback-text', model: 'fallback');
        $decorator = $this->decorator(new RoutedCall($this->throwing(), null, $fallback));

        self::assertSame('fallback-text', $decorator->complete('sys', 'usr'));
        self::assertSame(['from' => 'fallback'], $decorator->completeJson('sys', 'usr'));
        self::assertTrue($fallback->called());
    }

    public function testReThrowsWhenPrimaryThrowsAndNoFallback(): void
    {
        $decorator = $this->decorator(new RoutedCall($this->throwing()));

        $this->expectException(LlmException::class);
        $decorator->complete('sys', 'usr');
    }

    public function testIsConfiguredDelegatesToRouter(): void
    {
        $router = $this->createStub(LlmRouter::class);
        $router->method('isAnyConfigured')->willReturn(false);

        self::assertFalse((new RoutingLlmProvider($router, new AiUsageContext()))->isConfigured());
    }

    private function decorator(RoutedCall $call): RoutingLlmProvider
    {
        $router = $this->createStub(LlmRouter::class);
        $router->method('route')->willReturn($call);

        return new RoutingLlmProvider($router, new AiUsageContext());
    }

    private function throwing(): LlmProviderInterface
    {
        return new class implements LlmProviderInterface {
            public function isConfigured(): bool
            {
                return true;
            }

            public function complete(string $system, string $user, int $maxTokens = 4096, ?string $model = null): string
            {
                throw new LlmException('local model unavailable');
            }

            public function completeJson(string $system, string $user, int $maxTokens = 2048, ?string $model = null): array
            {
                throw new LlmException('local model unavailable');
            }

            public function getModel(): string
            {
                return 'throwing';
            }
        };
    }
}
