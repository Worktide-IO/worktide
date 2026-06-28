<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Channels\AdapterRegistry;
use App\Channels\SocialMediaConstraints;
use App\Channels\SocialPublishResult;
use App\Channels\SocialPublisherAdapter;
use App\Entity\Channel;
use App\Entity\Enum\SocialPostStatus;
use App\Entity\Enum\SocialPostTargetStatus;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Service\Social\SocialPostValidator;
use App\Service\Social\SocialPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

/**
 * Unit coverage for the publish fan-out + aggregate status recomputation. No
 * database: a stub adapter stands in for a network, so the state machine
 * (Published / Failed / PartiallyFailed, transient retry → eventual fail) is
 * pinned in isolation.
 */
final class SocialPublisherTest extends TestCase
{
    public function testSuccessfulPublishMarksTargetAndPostPublished(): void
    {
        $adapter = $this->adapter('social_x', SocialPublishResult::published('123', 'https://x/p/123'));
        $publisher = $this->publisher($adapter);

        $post = $this->post('hello world', ['social_x']);
        $publisher->publishPost($post);

        $target = $post->getTargets()->first();
        self::assertSame(SocialPostTargetStatus::Published, $target->getStatus());
        self::assertSame('123', $target->getExternalId());
        self::assertSame('https://x/p/123', $target->getPermalink());
        self::assertSame(SocialPostStatus::Published, $post->getStatus());
        self::assertNotNull($post->getPublishedAt());
    }

    public function testPermanentFailureMarksPostFailed(): void
    {
        $adapter = $this->adapter('social_x', SocialPublishResult::failed('bad credentials'));
        $publisher = $this->publisher($adapter);

        $post = $this->post('hello', ['social_x']);
        $publisher->publishPost($post);

        self::assertSame(SocialPostTargetStatus::Failed, $post->getTargets()->first()->getStatus());
        self::assertSame(SocialPostStatus::Failed, $post->getStatus());
    }

    public function testTransientFailureRetriesUntilCapThenFails(): void
    {
        $adapter = $this->adapter('social_x', SocialPublishResult::retry('5xx'));
        $publisher = $this->publisher($adapter);
        $post = $this->post('hello', ['social_x']);
        $target = $post->getTargets()->first();

        // Each pass is one attempt; below the cap it stays Queued for the next tick.
        $publisher->publishPost($post);
        self::assertSame(SocialPostTargetStatus::Queued, $target->getStatus());
        self::assertSame(SocialPostStatus::Publishing, $post->getStatus());

        $publisher->publishPost($post);
        self::assertSame(SocialPostTargetStatus::Queued, $target->getStatus());

        $publisher->publishPost($post); // attempt 3 == MAX_ATTEMPTS
        self::assertSame(SocialPostTargetStatus::Failed, $target->getStatus());
        self::assertSame(SocialPostStatus::Failed, $post->getStatus());
        self::assertSame(SocialPostTarget::MAX_ATTEMPTS, $target->getAttemptCount());
    }

    public function testMixedOutcomesYieldPartiallyFailed(): void
    {
        $ok = $this->adapter('social_ok', SocialPublishResult::published('1', null));
        $bad = $this->adapter('social_bad', SocialPublishResult::failed('nope'));
        $publisher = $this->publisher($ok, $bad);

        $post = $this->post('hello', ['social_ok', 'social_bad']);
        $publisher->publishPost($post);

        self::assertSame(SocialPostStatus::PartiallyFailed, $post->getStatus());
    }

    // --- helpers ----------------------------------------------------

    private function publisher(SocialPublisherAdapter ...$adapters): SocialPublisher
    {
        $registry = new AdapterRegistry([], [], [], [], $adapters);
        $hub = $this->createStub(HubInterface::class); // never called: post id is null in unit tests

        return new SocialPublisher($registry, new SocialPostValidator($registry), $hub, new NullLogger());
    }

    /** @param list<string> $adapterCodes one target per code */
    private function post(string $body, array $adapterCodes): SocialPost
    {
        $post = (new SocialPost())->setBody($body);
        foreach ($adapterCodes as $code) {
            $channel = (new Channel())->setName($code)->setAdapterCode($code);
            $post->addTarget((new SocialPostTarget())->setChannel($channel));
        }
        return $post;
    }

    private function adapter(string $code, SocialPublishResult $result): SocialPublisherAdapter
    {
        return new class($code, $result) implements SocialPublisherAdapter {
            public function __construct(private string $code, private SocialPublishResult $result) {}
            public function getCode(): string { return $this->code; }
            public function getLabel(): string { return $this->code; }
            public function maxLength(): int { return 500; }
            public function mediaConstraints(): SocialMediaConstraints { return new SocialMediaConstraints(); }
            public function publish(Channel $c, SocialPost $p, SocialPostTarget $t): SocialPublishResult { return $this->result; }
        };
    }
}
