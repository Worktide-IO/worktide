<?php

declare(strict_types=1);

namespace App\Tests\Channels\Adapter\Social;

use App\Channels\Adapter\Social\BlueskyAdapter;
use App\Entity\Channel;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Service\Social\SocialMediaResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit coverage for the Bluesky publisher over a mocked HTTP client: session
 * creation then createRecord, with permalink derivation from the AT-URI.
 */
final class BlueskyAdapterTest extends TestCase
{
    public function testPublishesTextPost(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['accessJwt' => 'jwt', 'did' => 'did:plc:abc', 'handle' => 'acme.bsky.social']) ?: '', ['http_code' => 200]),
            new MockResponse(json_encode(['uri' => 'at://did:plc:abc/app.bsky.feed.post/xyz', 'cid' => 'bafy']) ?: '', ['http_code' => 200]),
        ]);
        $adapter = new BlueskyAdapter($http, $this->resolver());

        $result = $adapter->publish($this->channel('acme.bsky.social', 'app-pw'), $this->post(), $this->target());

        self::assertTrue($result->published);
        self::assertSame('at://did:plc:abc/app.bsky.feed.post/xyz', $result->externalId);
        self::assertSame('https://bsky.app/profile/acme.bsky.social/post/xyz', $result->permalink);
    }

    public function testMissingCredentialsFailsFast(): void
    {
        $adapter = new BlueskyAdapter(new MockHttpClient(), $this->resolver());

        $result = $adapter->publish($this->channel('', ''), $this->post(), $this->target());

        self::assertTrue($result->failed);
    }

    public function testBadSessionIsPermanentFailure(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['error' => 'AuthenticationRequired', 'message' => 'invalid']) ?: '', ['http_code' => 401]),
        ]);
        $adapter = new BlueskyAdapter($http, $this->resolver());

        $result = $adapter->publish($this->channel('acme.bsky.social', 'wrong'), $this->post(), $this->target());

        self::assertTrue($result->failed);
    }

    private function resolver(): SocialMediaResolver
    {
        $resolver = $this->createStub(SocialMediaResolver::class);
        $resolver->method('resolve')->willReturn([]); // no media in these cases
        return $resolver;
    }

    private function channel(string $identifier, string $appPassword): Channel
    {
        return (new Channel())
            ->setName('bluesky')
            ->setAdapterCode('social_bluesky')
            ->setAuthConfig(['identifier' => $identifier, 'appPassword' => $appPassword]);
    }

    private function post(): SocialPost
    {
        return (new SocialPost())->setBody('hello atproto');
    }

    private function target(): SocialPostTarget
    {
        return (new SocialPostTarget())->setBodyOverride('hello atproto');
    }
}
