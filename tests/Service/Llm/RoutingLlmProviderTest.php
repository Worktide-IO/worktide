<?php

declare(strict_types=1);

namespace App\Tests\Service\Llm;

use App\Service\Llm\AiUsageContext;
use App\Service\Llm\LlmException;
use App\Service\Llm\LlmProviderInterface;
use App\Service\Llm\LlmRouter;
use App\Service\Llm\RoutingLlmProvider;
use App\Tests\Support\RecordingLlmProvider;
use PHPUnit\Framework\TestCase;

/**
 * The decorator that turns a router decision into a delegated call: it must use
 * the primary provider, fall back to the secondary on an {@see LlmException},
 * and re-throw when there is no fallback.
 */
final class RoutingLlmProviderTest extends TestCase
{
    public function testDelegatesToPrimary(): void
    {
        $primary = new RecordingLlmProvider(json: ['ok' => true], text: 'primary-text', model: 'primary');
        $decorator = $this->decorator([$primary, null]);

        self::assertSame('primary-text', $decorator->complete('sys', 'usr'));
        self::assertSame(['ok' => true], $decorator->completeJson('sys', 'usr'));
        self::assertSame('primary', $decorator->getModel());
        self::assertTrue($primary->called());
    }

    public function testFallsBackToSecondaryWhenPrimaryThrows(): void
    {
        $fallback = new RecordingLlmProvider(json: ['from' => 'fallback'], text: 'fallback-text', model: 'fallback');
        $decorator = $this->decorator([$this->throwing(), $fallback]);

        self::assertSame('fallback-text', $decorator->complete('sys', 'usr'));
        self::assertSame(['from' => 'fallback'], $decorator->completeJson('sys', 'usr'));
        self::assertTrue($fallback->called());
    }

    public function testReThrowsWhenPrimaryThrowsAndNoFallback(): void
    {
        $decorator = $this->decorator([$this->throwing(), null]);

        $this->expectException(LlmException::class);
        $decorator->complete('sys', 'usr');
    }

    public function testIsConfiguredDelegatesToRouter(): void
    {
        $router = $this->createStub(LlmRouter::class);
        $router->method('isAnyConfigured')->willReturn(false);

        self::assertFalse((new RoutingLlmProvider($router, new AiUsageContext()))->isConfigured());
    }

    /**
     * @param array{0: LlmProviderInterface, 1: ?LlmProviderInterface} $chain
     */
    private function decorator(array $chain): RoutingLlmProvider
    {
        $router = $this->createStub(LlmRouter::class);
        $router->method('route')->willReturn($chain);

        return new RoutingLlmProvider($router, new AiUsageContext());
    }

    private function throwing(): LlmProviderInterface
    {
        return new class implements LlmProviderInterface {
            public function isConfigured(): bool
            {
                return true;
            }

            public function complete(string $system, string $user, int $maxTokens = 4096): string
            {
                throw new LlmException('local model unavailable');
            }

            public function completeJson(string $system, string $user, int $maxTokens = 2048): array
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
