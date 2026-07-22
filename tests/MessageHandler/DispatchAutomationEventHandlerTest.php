<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Egress\EgressGuard;
use App\Entity\Channel;
use App\Entity\InboundEvent;
use App\Entity\Workspace;
use App\Message\DispatchAutomationEventMessage;
use App\MessageHandler\DispatchAutomationEventHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Unit coverage for the n8n dispatch handler over a mocked HTTP client: the
 * feature-off / egress-withheld short-circuits, the payload + signature shape,
 * and the recoverable-vs-unrecoverable failure split.
 */
final class DispatchAutomationEventHandlerTest extends TestCase
{
    private const URL = 'http://n8n:5678/webhook/worktide-inbound';

    public function testFeatureOffMakesNoRequest(): void
    {
        $requests = 0;
        $http = $this->http($requests);
        $handler = new DispatchAutomationEventHandler(
            $http, $this->emReturning($this->event()), new NullLogger(),
            new EgressGuard('automation'), webhookUrl: '', webhookSecret: '',
        );

        $handler(new DispatchAutomationEventMessage(Uuid::v4()));

        self::assertSame(0, $requests);
    }

    public function testWithheldWhenEgressNotApproved(): void
    {
        $requests = 0;
        $http = $this->http($requests);
        $handler = new DispatchAutomationEventHandler(
            $http, $this->emReturning($this->event()), new NullLogger(),
            new EgressGuard(''), webhookUrl: self::URL, webhookSecret: 's3cret',
        );

        $handler(new DispatchAutomationEventMessage(Uuid::v4()));

        self::assertSame(0, $requests);
    }

    public function testPostsSignedPayloadWhenApproved(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? [], 'body' => $options['body'] ?? ''];

            return new MockResponse('{"message":"Workflow was started"}', ['http_code' => 200]);
        });
        $handler = new DispatchAutomationEventHandler(
            $http, $this->emReturning($this->event()), new NullLogger(),
            new EgressGuard('automation'), webhookUrl: self::URL, webhookSecret: 's3cret',
        );

        $handler(new DispatchAutomationEventMessage(Uuid::v4()));

        self::assertSame('POST', $captured['method']);
        self::assertSame(self::URL, $captured['url']);
        // Header list is normalised to "Name: value" strings by the client.
        $headers = implode("\n", $captured['headers']);
        self::assertStringContainsString('X-Worktide-Event: inbound.received', $headers);
        self::assertStringContainsString('X-Worktide-Signature: sha256=' . hash_hmac('sha256', (string) $captured['body'], 's3cret'), $headers);

        $payload = json_decode((string) $captured['body'], true);
        self::assertSame('inbound.received', $payload['event']);
        self::assertSame('zabbix', $payload['channel']['adapter']);
        self::assertSame('CPU high', $payload['subject']);
        self::assertSame(5, $payload['sourceMetadata']['severity']);
    }

    public function testOmitsSignatureWhenNoSecret(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options['headers'] ?? [];

            return new MockResponse('', ['http_code' => 200]);
        });
        $handler = new DispatchAutomationEventHandler(
            $http, $this->emReturning($this->event()), new NullLogger(),
            new EgressGuard('automation'), webhookUrl: self::URL, webhookSecret: '',
        );

        $handler(new DispatchAutomationEventMessage(Uuid::v4()));

        self::assertStringNotContainsString('X-Worktide-Signature', implode("\n", $captured));
    }

    public function testNonSuccessThrowsForRetry(): void
    {
        $http = new MockHttpClient(new MockResponse('boom', ['http_code' => 500]));
        $handler = new DispatchAutomationEventHandler(
            $http, $this->emReturning($this->event()), new NullLogger(),
            new EgressGuard('automation'), webhookUrl: self::URL, webhookSecret: '',
        );

        $this->expectException(\RuntimeException::class);
        $handler(new DispatchAutomationEventMessage(Uuid::v4()));
    }

    public function testMissingEventDropsUnrecoverably(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $handler = new DispatchAutomationEventHandler(
            $this->http($n), $em, new NullLogger(),
            new EgressGuard('automation'), webhookUrl: self::URL, webhookSecret: '',
        );

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $handler(new DispatchAutomationEventMessage(Uuid::v4()));
    }

    // ---- helpers --------------------------------------------------

    private function event(): InboundEvent
    {
        $workspace = new Workspace();
        $channel = (new Channel())
            ->setName('Zabbix')
            ->setAdapterCode('zabbix')
            ->setWorkspace($workspace);

        return (new InboundEvent())
            ->setWorkspace($workspace)
            ->setChannel($channel)
            ->setExternalId('evt-1')
            ->setSubject('CPU high')
            ->setSenderRaw('web1 (Prod)')
            ->setBody('Host: web1')
            ->setSourceMetadata(['severity' => 5, 'resolved' => false]);
    }

    private function emReturning(InboundEvent $event): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($event);

        return $em;
    }

    /** MockHttpClient that counts requests and always 200s (fails the test if hit when it shouldn't be). */
    private function http(?int &$count): MockHttpClient
    {
        $count = 0;

        return new MockHttpClient(function () use (&$count): MockResponse {
            ++$count;

            return new MockResponse('', ['http_code' => 200]);
        });
    }
}
