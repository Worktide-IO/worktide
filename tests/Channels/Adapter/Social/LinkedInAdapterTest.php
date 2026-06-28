<?php

declare(strict_types=1);

namespace App\Tests\Channels\Adapter\Social;

use App\Channels\Adapter\Social\LinkedInAdapter;
use App\Channels\OAuth\OAuth2Client;
use App\Entity\Channel;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Service\Social\SocialMediaResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit coverage for the LinkedIn publisher over a mocked HTTP client and a
 * stubbed OAuth2 token source. With an explicit authorUrn no /userinfo call is
 * needed, so a text post is a single request whose x-restli-id header becomes
 * the post URN.
 */
final class LinkedInAdapterTest extends TestCase
{
    public function testPublishesTextPost(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 201, 'response_headers' => ['x-restli-id' => 'urn:li:share:123']]),
        ]);
        $adapter = new LinkedInAdapter($http, $this->oauth(), $this->resolver());

        $result = $adapter->publish($this->channel('urn:li:person:abc'), $this->post(), $this->target());

        self::assertTrue($result->published);
        self::assertSame('urn:li:share:123', $result->externalId);
        self::assertSame('https://www.linkedin.com/feed/update/urn:li:share:123', $result->permalink);
    }

    public function testRejectedPostIsPermanentFailure(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['message' => 'forbidden']) ?: '', ['http_code' => 403]),
        ]);
        $adapter = new LinkedInAdapter($http, $this->oauth(), $this->resolver());

        $result = $adapter->publish($this->channel('urn:li:person:abc'), $this->post(), $this->target());

        self::assertTrue($result->failed);
    }

    public function testServerErrorIsRetryable(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['message' => 'oops']) ?: '', ['http_code' => 502]),
        ]);
        $adapter = new LinkedInAdapter($http, $this->oauth(), $this->resolver());

        $result = $adapter->publish($this->channel('urn:li:person:abc'), $this->post(), $this->target());

        self::assertTrue($result->retry);
    }

    private function oauth(): OAuth2Client
    {
        $oauth = $this->createStub(OAuth2Client::class);
        $oauth->method('ensureAccessToken')->willReturn('token');
        return $oauth;
    }

    private function resolver(): SocialMediaResolver
    {
        $resolver = $this->createStub(SocialMediaResolver::class);
        $resolver->method('resolve')->willReturn([]);
        return $resolver;
    }

    private function channel(string $authorUrn): Channel
    {
        return (new Channel())
            ->setName('linkedin')
            ->setAdapterCode('social_linkedin')
            ->setOutboundConfig(['authorUrn' => $authorUrn]);
    }

    private function post(): SocialPost
    {
        return (new SocialPost())->setBody('hello linkedin');
    }

    private function target(): SocialPostTarget
    {
        return (new SocialPostTarget())->setBodyOverride('hello linkedin');
    }
}
