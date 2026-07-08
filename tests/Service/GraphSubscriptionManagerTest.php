<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Channels\Adapter\EmailGraph\GraphSubscriptionManager;
use App\Channels\OAuth\OAuth2Client;
use App\Entity\Channel;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Unit coverage for the Graph push-subscription lifecycle: subscribe builds the
 * correct payload and stashes state, renew PATCHes, and a 404 on renew falls
 * back to a fresh subscribe. HttpClient + OAuth are mocked (no network/DB).
 */
final class GraphSubscriptionManagerTest extends TestCase
{
    private const PUBLIC_BASE = 'https://api.example.test';

    public function testSubscribeSendsCorrectPayloadAndStoresState(): void
    {
        $channel = $this->channel();

        $captured = null;
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$captured): ResponseInterface {
                $captured = ['method' => $method, 'url' => $url, 'json' => $options['json'] ?? null];

                return $this->response(201, ['id' => 'sub-123', 'expirationDateTime' => '2030-01-01T00:00:00Z']);
            });

        $this->manager($http)->subscribe($channel);

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://graph.microsoft.com/v1.0/subscriptions', $captured['url']);
        self::assertSame('created', $captured['json']['changeType']);
        self::assertSame(
            self::PUBLIC_BASE . '/v1/inbound/webhooks/tok_1234567890abcdef',
            $captured['json']['notificationUrl'],
        );
        self::assertSame("me/mailFolders('Inbox')/messages", $captured['json']['resource']);
        self::assertNotEmpty($captured['json']['clientState']);

        $sub = $channel->getAuthConfig()['graphSubscription'];
        self::assertSame('sub-123', $sub['subscriptionId']);
        self::assertSame($captured['json']['clientState'], $sub['clientState']);
        self::assertSame('2030-01-01T00:00:00Z', $sub['expiresAt']);
    }

    public function testRenewPatchesExistingSubscription(): void
    {
        $channel = $this->channel();
        $channel->setAuthConfig([
            'graphSubscription' => ['subscriptionId' => 'sub-9', 'clientState' => 'sec', 'expiresAt' => '2020-01-01T00:00:00Z'],
        ]);

        $captured = null;
        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willReturnCallback(
            function (string $method, string $url) use (&$captured): ResponseInterface {
                $captured = ['method' => $method, 'url' => $url];

                return $this->response(200, ['id' => 'sub-9', 'expirationDateTime' => '2031-01-01T00:00:00Z']);
            },
        );

        $this->manager($http)->renew($channel);

        self::assertSame('PATCH', $captured['method']);
        self::assertSame('https://graph.microsoft.com/v1.0/subscriptions/sub-9', $captured['url']);
        self::assertSame('2031-01-01T00:00:00Z', $channel->getAuthConfig()['graphSubscription']['expiresAt']);
        // Same subscription retained, secret untouched.
        self::assertSame('sub-9', $channel->getAuthConfig()['graphSubscription']['subscriptionId']);
        self::assertSame('sec', $channel->getAuthConfig()['graphSubscription']['clientState']);
    }

    public function testRenewFallsBackToSubscribeWhenGone(): void
    {
        $channel = $this->channel();
        $channel->setAuthConfig([
            'graphSubscription' => ['subscriptionId' => 'dead', 'clientState' => 'old', 'expiresAt' => '2020-01-01T00:00:00Z'],
        ]);

        $calls = [];
        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willReturnCallback(
            function (string $method) use (&$calls): ResponseInterface {
                $calls[] = $method;
                // First call is the PATCH → 404 (gone); second is the POST re-subscribe.
                return $method === 'PATCH'
                    ? $this->response(404, [])
                    : $this->response(201, ['id' => 'sub-new', 'expirationDateTime' => '2032-01-01T00:00:00Z']);
            },
        );

        $this->manager($http)->renew($channel);

        self::assertSame(['PATCH', 'POST'], $calls);
        self::assertSame('sub-new', $channel->getAuthConfig()['graphSubscription']['subscriptionId']);
    }

    private function channel(): Channel
    {
        return (new Channel())
            ->setAdapterCode('email_graph')
            ->setInboundConfig(['token' => 'tok_1234567890abcdef', 'folder' => 'Inbox', 'mailboxUser' => 'me']);
    }

    private function manager(HttpClientInterface $http): GraphSubscriptionManager
    {
        $oauth = $this->createMock(OAuth2Client::class);
        $oauth->method('ensureAccessToken')->willReturn('access-token');
        $em = $this->createMock(EntityManagerInterface::class);

        return new GraphSubscriptionManager($em, $http, $oauth, self::PUBLIC_BASE);
    }

    /** @param array<string, mixed> $data */
    private function response(int $status, array $data): ResponseInterface
    {
        $r = $this->createMock(ResponseInterface::class);
        $r->method('getStatusCode')->willReturn($status);
        $r->method('toArray')->willReturn($data);

        return $r;
    }
}
