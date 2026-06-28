<?php

declare(strict_types=1);

namespace App\Tests\Channels\Adapter\Social;

use App\Channels\Adapter\Social\MastodonAdapter;
use App\Entity\Channel;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Service\Social\SocialMediaResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit coverage for the Mastodon publisher over a mocked HTTP client — no
 * network, no media (so the resolver's EM/FileStorage are never touched).
 */
final class MastodonAdapterTest extends TestCase
{
    public function testPublishesTextStatus(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['id' => '42', 'url' => 'https://m.test/@acme/42']) ?: '', ['http_code' => 200]),
        ]);
        $adapter = new MastodonAdapter($http, $this->resolver());

        $result = $adapter->publish($this->channel('https://m.test', 'tok'), $this->post('hello fediverse'), $this->target());

        self::assertTrue($result->published);
        self::assertSame('42', $result->externalId);
        self::assertSame('https://m.test/@acme/42', $result->permalink);
    }

    public function testMissingConfigFailsFast(): void
    {
        $adapter = new MastodonAdapter(new MockHttpClient(), $this->resolver());

        $result = $adapter->publish($this->channel('', ''), $this->post('x'), $this->target());

        self::assertTrue($result->failed);
    }

    public function testServerErrorIsRetryable(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['error' => 'overloaded']) ?: '', ['http_code' => 503]),
        ]);
        $adapter = new MastodonAdapter($http, $this->resolver());

        $result = $adapter->publish($this->channel('https://m.test', 'tok'), $this->post('x'), $this->target());

        self::assertTrue($result->retry);
    }

    private function resolver(): SocialMediaResolver
    {
        $resolver = $this->createStub(SocialMediaResolver::class);
        $resolver->method('resolve')->willReturn([]); // no media in these cases
        return $resolver;
    }

    private function channel(string $instanceUrl, string $token): Channel
    {
        return (new Channel())
            ->setName('mastodon')
            ->setAdapterCode('social_mastodon')
            ->setOutboundConfig(['instanceUrl' => $instanceUrl])
            ->setAuthConfig(['accessToken' => $token]);
    }

    private function post(string $body): SocialPost
    {
        return (new SocialPost())->setBody($body);
    }

    private function target(): SocialPostTarget
    {
        // bodyOverride keeps effectiveBody() self-contained (no parent post needed).
        return (new SocialPostTarget())->setBodyOverride('hello');
    }
}
