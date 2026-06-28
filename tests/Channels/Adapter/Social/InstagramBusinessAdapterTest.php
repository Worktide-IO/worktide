<?php

declare(strict_types=1);

namespace App\Tests\Channels\Adapter\Social;

use App\Channels\Adapter\Social\InstagramBusinessAdapter;
use App\Entity\Channel;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Service\Social\SocialMediaUrlSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

/**
 * Unit coverage for the Instagram publisher over a mocked HTTP client: single
 * image → container → publish → permalink.
 */
final class InstagramBusinessAdapterTest extends TestCase
{
    private function signer(): SocialMediaUrlSigner
    {
        return new SocialMediaUrlSigner('secret', 'https://api.worktide.test');
    }

    public function testPublishesSingleImage(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['id' => 'CONTAINER_1']) ?: '', ['http_code' => 200]),  // /media
            new MockResponse(json_encode(['id' => 'MEDIA_1']) ?: '', ['http_code' => 200]),       // /media_publish
            new MockResponse(json_encode(['permalink' => 'https://www.instagram.com/p/AbC/']) ?: '', ['http_code' => 200]),
        ]);
        $adapter = new InstagramBusinessAdapter($http, $this->signer());

        $result = $adapter->publish($this->channel('IGUSER', 'tok'), $this->postWithImage(), $this->target());

        self::assertTrue($result->published);
        self::assertSame('MEDIA_1', $result->externalId);
        self::assertSame('https://www.instagram.com/p/AbC/', $result->permalink);
    }

    public function testNoImageFails(): void
    {
        $adapter = new InstagramBusinessAdapter(new MockHttpClient(), $this->signer());

        $result = $adapter->publish($this->channel('IGUSER', 'tok'), new SocialPost(), $this->target());

        self::assertTrue($result->failed);
    }

    public function testMissingConfigFailsFast(): void
    {
        $adapter = new InstagramBusinessAdapter(new MockHttpClient(), $this->signer());

        self::assertTrue($adapter->publish($this->channel('', ''), $this->postWithImage(), $this->target())->failed);
    }

    private function channel(string $igUserId, string $token): Channel
    {
        return (new Channel())
            ->setName('ig')
            ->setAdapterCode('social_instagram')
            ->setOutboundConfig(['igUserId' => $igUserId])
            ->setAuthConfig(['accessToken' => $token]);
    }

    private function postWithImage(): SocialPost
    {
        return (new SocialPost())
            ->setBody('hello instagram')
            ->setMediaRefs([['fileId' => Uuid::v7()->toRfc4122(), 'mimeType' => 'image/jpeg']]);
    }

    private function target(): SocialPostTarget
    {
        return (new SocialPostTarget())->setBodyOverride('hello instagram');
    }
}
