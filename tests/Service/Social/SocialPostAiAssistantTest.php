<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Channels\AdapterRegistry;
use App\Channels\SocialMediaConstraints;
use App\Channels\SocialPublishResult;
use App\Channels\SocialPublisherAdapter;
use App\Entity\Channel;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Service\Llm\LlmProviderInterface;
use App\Service\Social\SocialPostAiAssistant;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for AI suggestions with a stubbed LLM (no real API call):
 * per-network suggestion, hard-cap truncation, fan-out across targets, and the
 * unknown-network guard.
 */
final class SocialPostAiAssistantTest extends TestCase
{
    public function testSuggestForAdapterTruncatesToNetworkLimit(): void
    {
        $assistant = $this->assistant(suggestion: str_repeat('x', 50), maxLength: 20);

        $result = $assistant->suggestForAdapter($this->post('draft'), 'social_test');

        self::assertSame('social_test', $result['adapterCode']);
        self::assertSame('TestNet', $result['network']);
        self::assertSame(20, $result['maxLength']);
        self::assertSame(20, $result['length']);
        self::assertSame(20, mb_strlen($result['suggestion']));
    }

    public function testSuggestForAdapterKeepsShortSuggestion(): void
    {
        $assistant = $this->assistant(suggestion: 'Crisp post copy.', maxLength: 280);

        $result = $assistant->suggestForAdapter($this->post('draft'), 'social_test');

        self::assertSame('Crisp post copy.', $result['suggestion']);
    }

    public function testSuggestForPostCoversEachTargetNetwork(): void
    {
        $assistant = $this->assistant(suggestion: 'ok', maxLength: 280);

        $results = $assistant->suggestForPost($this->post('draft'));

        self::assertCount(1, $results);
        self::assertSame('social_test', $results[0]['adapterCode']);
    }

    public function testUnknownNetworkRejected(): void
    {
        $assistant = $this->assistant(suggestion: 'ok', maxLength: 280);

        $this->expectException(\InvalidArgumentException::class);
        $assistant->suggestForAdapter($this->post('draft'), 'social_missing');
    }

    public function testAvailabilityReflectsProvider(): void
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('isConfigured')->willReturn(false);
        $assistant = new SocialPostAiAssistant($llm, new AdapterRegistry([], [], [], [], []));

        self::assertFalse($assistant->isAvailable());
    }

    // --- helpers ----------------------------------------------------

    private function assistant(string $suggestion, int $maxLength): SocialPostAiAssistant
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('isConfigured')->willReturn(true);
        $llm->method('complete')->willReturn($suggestion);

        $registry = new AdapterRegistry([], [], [], [], [$this->adapter('social_test', $maxLength)]);

        return new SocialPostAiAssistant($llm, $registry);
    }

    private function post(string $body): SocialPost
    {
        $post = (new SocialPost())->setBody($body);
        $channel = (new Channel())->setName('test')->setAdapterCode('social_test');
        $post->addTarget((new SocialPostTarget())->setChannel($channel));
        return $post;
    }

    private function adapter(string $code, int $maxLength): SocialPublisherAdapter
    {
        return new class($code, $maxLength) implements SocialPublisherAdapter {
            public function __construct(private string $code, private int $max) {}
            public function getCode(): string { return $this->code; }
            public function getLabel(): string { return 'TestNet'; }
            public function maxLength(): int { return $this->max; }
            public function mediaConstraints(): SocialMediaConstraints { return new SocialMediaConstraints(); }
            public function publish(Channel $c, SocialPost $p, SocialPostTarget $t): SocialPublishResult { return SocialPublishResult::failed('n/a'); }
        };
    }
}
