<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Channels\AdapterRegistry;
use App\Channels\SocialMediaConstraints;
use App\Channels\SocialPublishResult;
use App\Channels\SocialPublisherAdapter;
use App\Entity\Channel;
use App\Entity\Product;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
use App\Service\Ai\MarketingCopyAssistant;
use App\Service\Llm\LlmProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for marketing-draft generation with a stubbed LLM (no real API
 * call): a valid variant is kept and hard-capped, an unknown network is dropped,
 * a duplicate network is ignored (first wins), and the summary is cleaned.
 */
final class MarketingCopyAssistantTest extends TestCase
{
    public function testDraftKeepsKnownNetworkCapsBodyAndDropsUnknown(): void
    {
        $assistant = $this->assistant(maxLength: 20, raw: [
            'summary' => 'A great tool.',
            'variants' => [
                ['adapterCode' => 'social_test', 'body' => str_repeat('x', 50)],
                ['adapterCode' => 'social_unknown', 'body' => 'should be dropped'],
                ['network' => 'TestNet', 'body' => 'duplicate network, ignored'],
            ],
            'reasoning' => 'because it fits',
        ]);

        $result = $assistant->draftSocialPosts($this->product());

        self::assertSame('A great tool.', $result['suggestion']['summary']);
        self::assertSame('because it fits', $result['reasoning']);
        self::assertCount(1, $result['suggestion']['variants']);

        $variant = $result['suggestion']['variants'][0];
        self::assertSame('social_test', $variant['adapterCode']);
        self::assertSame('TestNet', $variant['network']);
        self::assertSame(20, mb_strlen($variant['body']));
    }

    public function testDraftDropsEmptyBodies(): void
    {
        $assistant = $this->assistant(maxLength: 280, raw: [
            'summary' => 'x',
            'variants' => [['adapterCode' => 'social_test', 'body' => '   ']],
        ]);

        $result = $assistant->draftSocialPosts($this->product());

        self::assertSame([], $result['suggestion']['variants']);
    }

    public function testAvailabilityReflectsProvider(): void
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('isConfigured')->willReturn(false);
        $assistant = new MarketingCopyAssistant($llm, new AdapterRegistry([], [], [], [], []), $this->createStub(ChannelRepository::class));

        self::assertFalse($assistant->isAvailable());
    }

    // --- helpers ----------------------------------------------------

    /**
     * @param array<string, mixed> $raw
     */
    private function assistant(int $maxLength, array $raw): MarketingCopyAssistant
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('isConfigured')->willReturn(true);
        $llm->method('completeJson')->willReturn($raw);

        $registry = new AdapterRegistry([], [], [], [], [$this->adapter('social_test', $maxLength)]);

        // No connected channels → the assistant falls back to the known social adapters.
        $channels = $this->createStub(ChannelRepository::class);
        $channels->method('findEnabledSocial')->willReturn([]);

        return new MarketingCopyAssistant($llm, $registry, $channels);
    }

    private function product(): Product
    {
        return (new Product())->setName('Widget')->setSlug('widget')->setWorkspace(new Workspace());
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
