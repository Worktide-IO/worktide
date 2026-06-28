<?php

declare(strict_types=1);

namespace App\Tests\Channels\Adapter\Social;

use App\Channels\Adapter\Social\FacebookPageAdapter;
use App\Entity\Channel;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Service\Social\SocialMediaResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit coverage for the Facebook Page publisher (text post path) over a mocked
 * HTTP client.
 */
final class FacebookPageAdapterTest extends TestCase
{
    public function testPublishesTextPost(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['id' => 'PAGE_1']) ?: '', ['http_code' => 200]),
        ]);
        $adapter = new FacebookPageAdapter($http, $this->resolver());

        $result = $adapter->publish($this->channel('PAGE', 'tok'), $this->post(), $this->target());

        self::assertTrue($result->published);
        self::assertSame('PAGE_1', $result->externalId);
        self::assertSame('https://www.facebook.com/PAGE_1', $result->permalink);
    }

    public function testMissingConfigFailsFast(): void
    {
        $adapter = new FacebookPageAdapter(new MockHttpClient(), $this->resolver());

        self::assertTrue($adapter->publish($this->channel('', ''), $this->post(), $this->target())->failed);
    }

    public function testGraphErrorIsPermanentFailure(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['error' => ['message' => 'bad token', 'code' => 190]]) ?: '', ['http_code' => 400]),
        ]);
        $adapter = new FacebookPageAdapter($http, $this->resolver());

        self::assertTrue($adapter->publish($this->channel('PAGE', 'tok'), $this->post(), $this->target())->failed);
    }

    private function resolver(): SocialMediaResolver
    {
        $resolver = $this->createStub(SocialMediaResolver::class);
        $resolver->method('resolve')->willReturn([]);
        return $resolver;
    }

    private function channel(string $pageId, string $token): Channel
    {
        return (new Channel())
            ->setName('fb')
            ->setAdapterCode('social_facebook')
            ->setOutboundConfig(['pageId' => $pageId])
            ->setAuthConfig(['pageAccessToken' => $token]);
    }

    private function post(): SocialPost
    {
        return (new SocialPost())->setBody('hello facebook');
    }

    private function target(): SocialPostTarget
    {
        return (new SocialPostTarget())->setBodyOverride('hello facebook');
    }
}
