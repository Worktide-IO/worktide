<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Channels\Adapter\Email\MailThreader;
use App\Channels\Adapter\EmailGraph\EmailGraphAdapter;
use App\Channels\OAuth\OAuth2Client;
use App\Entity\Channel;
use App\Repository\ContactRepository;
use App\Repository\ConversationRepository;
use App\Repository\InboundEventRepository;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * consumeWebhook() is fail-closed on clientState: a Graph notification whose
 * clientState doesn't match the one stored at subscribe-time is ignored without
 * any Graph fetch; a valid one delegates to the delta pull and advances the cursor.
 */
final class EmailGraphConsumeWebhookTest extends TestCase
{
    private const SECRET = 'the-stored-client-state';

    public function testWrongClientStateIsIgnoredWithoutFetch(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request'); // no Graph call on a spoofed notification

        $adapter = $this->adapter($http, self::never());
        $result = $adapter->consumeWebhook($this->channel(), $this->notification('WRONG'));

        self::assertSame([], $result->events);
    }

    public function testValidClientStateDelegatesToPullAndAdvancesCursor(): void
    {
        // pull() issues one GET delta request → return an empty delta page with a deltaLink.
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn(
            $this->response(['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?$deltatoken=NEXT']),
        );

        $channel = $this->channel();
        $adapter = $this->adapter($http, self::once());
        $result = $adapter->consumeWebhook($channel, $this->notification(self::SECRET));

        self::assertSame([], $result->events);
        // Cursor advanced + persisted on the channel for the controller's flush.
        self::assertSame(
            'https://graph.microsoft.com/v1.0/delta?$deltatoken=NEXT',
            $channel->getInboundConfig()['cursor'],
        );
    }

    private function channel(): Channel
    {
        return (new Channel())
            ->setAdapterCode('email_graph')
            ->setInboundConfig(['folder' => 'Inbox', 'mailboxUser' => 'me'])
            ->setAuthConfig(['graphSubscription' => ['clientState' => self::SECRET]]);
    }

    private function notification(string $clientState): Request
    {
        $body = json_encode(['value' => [['subscriptionId' => 's1', 'clientState' => $clientState]]]);

        return Request::create('/v1/inbound/webhooks/x', 'POST', [], [], [], [], (string) $body);
    }

    private function adapter(HttpClientInterface $http, \PHPUnit\Framework\MockObject\Rule\InvocationOrder $tokenCalls): EmailGraphAdapter
    {
        $oauth = $this->createMock(OAuth2Client::class);
        $oauth->expects($tokenCalls)->method('ensureAccessToken')->willReturn('access-token');

        // MailThreader is final (can't be doubled) but is never invoked on an
        // empty delta — build a real one with mocked collaborators.
        $threader = new MailThreader(
            $this->createMock(ConversationRepository::class),
            $this->createMock(InboundEventRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );

        return new EmailGraphAdapter(
            $this->createMock(EntityManagerInterface::class),
            $http,
            $oauth,
            $this->createMock(InboundEventRepository::class),
            $this->createMock(ContactRepository::class),
            $threader,
            new FileStorage($this->createMock(FilesystemOperator::class)),
        );
    }

    /** @param array<string, mixed> $data */
    private function response(array $data): ResponseInterface
    {
        $r = $this->createMock(ResponseInterface::class);
        $r->method('toArray')->willReturn($data);
        $r->method('getStatusCode')->willReturn(200);

        return $r;
    }
}
