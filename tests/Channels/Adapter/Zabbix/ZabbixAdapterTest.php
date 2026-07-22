<?php

declare(strict_types=1);

namespace App\Tests\Channels\Adapter\Zabbix;

use App\Channels\Adapter\Zabbix\ZabbixAdapter;
use App\Entity\Channel;
use App\Entity\InboundEvent;
use App\Entity\Workspace;
use App\Repository\InboundEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit coverage for the Zabbix pull adapter over a mocked JSON-RPC client
 * (no network). Pins the problem→InboundEvent mapping, dedup, the stateful
 * recovery diff, the host-group filter, and selfTest verdicts against the
 * shapes captured from the live Zabbix 7.4.7 API.
 */
final class ZabbixAdapterTest extends TestCase
{
    public function testPullMapsProblemsToEvents(): void
    {
        $adapter = new ZabbixAdapter(
            $this->http([
                'problem.get' => [$this->problem('100', '20', 'CPU high', '4', '1784000000')],
                'trigger.get' => [$this->trigger('20', '55', 'web1', 'web1 (Prod)')],
                'event.get' => [],
            ]),
            $this->createStub(EntityManagerInterface::class),
            $this->eventsRepo(),
        );
        $channel = $this->channel();

        $result = $adapter->pull($channel);

        self::assertCount(1, $result->events);
        $event = $result->events[0];
        self::assertSame('100', $event->getExternalId());
        self::assertSame('CPU high', $event->getSubject());
        self::assertSame('web1 (Prod)', $event->getSenderRaw());
        $meta = $event->getSourceMetadata();
        self::assertSame('55', $meta['hostid']);
        self::assertSame('20', $meta['triggerId']);
        self::assertSame(4, $meta['severity']);
        self::assertSame('Hoch', $meta['severityLabel']);
        self::assertFalse($meta['resolved']);
        self::assertSame(['100'], $channel->getInboundConfig()['openEventIds']);
    }

    public function testKnownEventIsDedupedButStillTrackedOpen(): void
    {
        $events = $this->createStub(InboundEventRepository::class);
        $events->method('findByExternalId')->willReturnCallback(
            fn (Channel $c, string $id): ?InboundEvent => $id === '100' ? new InboundEvent() : null,
        );
        $adapter = new ZabbixAdapter(
            $this->http([
                'problem.get' => [$this->problem('100', '20', 'CPU high', '4', '1784000000')],
                'trigger.get' => [$this->trigger('20', '55', 'web1', 'web1 (Prod)')],
                'event.get' => [],
            ]),
            $this->createStub(EntityManagerInterface::class),
            $events,
        );
        $channel = $this->channel();

        $result = $adapter->pull($channel);

        self::assertCount(0, $result->events); // replay, no new event
        self::assertSame(['100'], $channel->getInboundConfig()['openEventIds']); // still tracked
    }

    public function testRecoveryEmittedWhenProblemDisappears(): void
    {
        $origin = (new InboundEvent())
            ->setExternalId('500')
            ->setSubject('Disk full')
            ->setSenderRaw('db1 (Prod)')
            ->setSourceMetadata(['hostid' => '77', 'triggerId' => '30', 'resolved' => false]);

        $events = $this->createStub(InboundEventRepository::class);
        $events->method('findByExternalId')->willReturnCallback(
            fn (Channel $c, string $id): ?InboundEvent => $id === '500' ? $origin : null,
        );

        // No current problems → the previously-open 500 recovered.
        $adapter = new ZabbixAdapter(
            $this->http(['problem.get' => [], 'event.get' => []]),
            $this->createStub(EntityManagerInterface::class),
            $events,
        );
        $channel = $this->channel(openEventIds: ['500']);

        $result = $adapter->pull($channel);

        self::assertCount(1, $result->events);
        $recovery = $result->events[0];
        self::assertSame('resolved:500', $recovery->getExternalId());
        self::assertStringStartsWith('Behoben: Disk full', (string) $recovery->getSubject());
        self::assertTrue($recovery->getSourceMetadata()['resolved']);
        self::assertSame('77', $recovery->getSourceMetadata()['hostid']);
        self::assertSame([], $channel->getInboundConfig()['openEventIds']);
    }

    public function testHostGroupFilterResolvesAndNarrows(): void
    {
        $captured = [];
        $http = $this->http([
            'hostgroup.get' => [['groupid' => '17']],
            'problem.get' => [],
            'event.get' => [],
        ], $captured);
        $adapter = new ZabbixAdapter($http, $this->createStub(EntityManagerInterface::class), $this->eventsRepo());

        $adapter->pull($this->channel(hostGroup: 'WapplerSystems'));

        self::assertSame(['17'], $captured['problem.get']['params']['groupids'] ?? null);
        // The event-cursor query is narrowed to the same host group.
        self::assertSame(['17'], $captured['event.get']['params']['groupids'] ?? null);
    }

    public function testSeedsEventCursorWithoutBackfillOnFirstRun(): void
    {
        // First run: no eventCursor yet → event.get seeds it to the newest id and
        // must NOT ingest historical problems as short-lived flaps.
        $adapter = new ZabbixAdapter(
            $this->http([
                'problem.get' => [],
                'event.get' => [$this->problem('9001', '20', 'old flap', '2', '1784000000')],
            ]),
            $this->createStub(EntityManagerInterface::class),
            $this->eventsRepo(),
        );
        $channel = $this->channel();

        $result = $adapter->pull($channel);

        self::assertCount(0, $result->events);
        self::assertSame('9001', $channel->getInboundConfig()['eventCursor']);
    }

    public function testShortLivedFlapEmitsRaiseAndRecovery(): void
    {
        // A cursor already exists → event.get returns a problem (200) that opened
        // since the cursor but is NOT in the current open set → it flapped within
        // the interval. Expect a raise AND its recovery, and the cursor advances.
        $adapter = new ZabbixAdapter(
            $this->http([
                'problem.get' => [],
                'event.get' => [$this->problem('200', '20', 'CPU high', '4', '1784000500')],
                'trigger.get' => [$this->trigger('20', '55', 'web1', 'web1 (Prod)')],
            ]),
            $this->createStub(EntityManagerInterface::class),
            $this->eventsRepo(),
        );
        $channel = $this->channel(eventCursor: '150');

        $result = $adapter->pull($channel);

        self::assertCount(2, $result->events);
        [$raise, $recovery] = $result->events;
        self::assertSame('200', $raise->getExternalId());
        self::assertFalse($raise->getSourceMetadata()['resolved']);
        self::assertSame('resolved:200', $recovery->getExternalId());
        self::assertTrue($recovery->getSourceMetadata()['resolved']);
        self::assertSame('55', $recovery->getSourceMetadata()['hostid']);
        // Recovery clock is after the raise so the threader ends the thread closed.
        self::assertGreaterThanOrEqual($raise->getReceivedAt(), $recovery->getReceivedAt());
        self::assertSame('200', $channel->getInboundConfig()['eventCursor']);
        self::assertSame([], $channel->getInboundConfig()['openEventIds']);
    }

    public function testCurrentlyOpenEventFromCursorIsNotDoubleEmitted(): void
    {
        // event.get surfaces eventid 100, which is ALSO currently open (problem.get)
        // → the open-set path owns it; the cursor path must not emit a recovery.
        $adapter = new ZabbixAdapter(
            $this->http([
                'problem.get' => [$this->problem('100', '20', 'CPU high', '4', '1784000000')],
                'event.get' => [$this->problem('100', '20', 'CPU high', '4', '1784000000')],
                'trigger.get' => [$this->trigger('20', '55', 'web1', 'web1 (Prod)')],
            ]),
            $this->createStub(EntityManagerInterface::class),
            $this->eventsRepo(),
        );
        $channel = $this->channel(eventCursor: '50');

        $result = $adapter->pull($channel);

        self::assertCount(1, $result->events); // one raise, no phantom recovery
        self::assertSame('100', $result->events[0]->getExternalId());
        self::assertFalse($result->events[0]->getSourceMetadata()['resolved']);
    }

    public function testSelfTestOkWhenReachableAndAuthorized(): void
    {
        $http = new MockHttpClient([
            new MockResponse((string) json_encode(['jsonrpc' => '2.0', 'result' => '7.4.7', 'id' => 1])),
            new MockResponse((string) json_encode(['jsonrpc' => '2.0', 'result' => [['hostid' => '1']], 'id' => 1])),
        ]);
        $adapter = new ZabbixAdapter($http, $this->createStub(EntityManagerInterface::class), $this->eventsRepo());

        $result = $adapter->selfTest($this->channel());

        self::assertSame('ok', $result->status);
        self::assertStringContainsString('7.4.7', $result->message);
    }

    public function testSelfTestFailsOnAuthError(): void
    {
        $http = new MockHttpClient([
            new MockResponse((string) json_encode(['jsonrpc' => '2.0', 'result' => '7.4.7', 'id' => 1])),
            new MockResponse((string) json_encode(['jsonrpc' => '2.0', 'error' => ['message' => 'Not authorized', 'data' => ''], 'id' => 1])),
        ]);
        $adapter = new ZabbixAdapter($http, $this->createStub(EntityManagerInterface::class), $this->eventsRepo());

        $result = $adapter->selfTest($this->channel());

        self::assertSame('failed', $result->status);
    }

    // ---- helpers --------------------------------------------------

    private function eventsRepo(): InboundEventRepository
    {
        $events = $this->createStub(InboundEventRepository::class);
        $events->method('findByExternalId')->willReturn(null);

        return $events;
    }

    /**
     * MockHttpClient that routes each JSON-RPC request by its `method`, wrapping
     * the mapped value in a `{result: …}` envelope. Optionally records each
     * request's decoded payload into $captured keyed by method.
     *
     * @param array<string, mixed> $byMethod
     * @param array<string, array<string, mixed>> $captured
     */
    private function http(array $byMethod, array &$captured = []): MockHttpClient
    {
        return new MockHttpClient(function (string $httpMethod, string $url, array $options) use ($byMethod, &$captured): MockResponse {
            $payload = $this->decodePayload($options);
            $zbxMethod = \is_string($payload['method'] ?? null) ? $payload['method'] : '';
            $captured[$zbxMethod] = $payload;

            if (!\array_key_exists($zbxMethod, $byMethod)) {
                return new MockResponse((string) json_encode([
                    'jsonrpc' => '2.0',
                    'error' => ['message' => 'unexpected method', 'data' => $zbxMethod],
                    'id' => 1,
                ]));
            }

            return new MockResponse((string) json_encode([
                'jsonrpc' => '2.0',
                'result' => $byMethod[$zbxMethod],
                'id' => 1,
            ]));
        });
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function decodePayload(array $options): array
    {
        $body = $options['body'] ?? null;
        if (\is_string($body)) {
            $decoded = json_decode($body, true);

            return \is_array($decoded) ? $decoded : [];
        }
        // Fallback: some client versions expose the pre-serialised json option.
        return \is_array($options['json'] ?? null) ? $options['json'] : [];
    }

    /** @param list<string> $openEventIds */
    private function channel(?string $hostGroup = null, array $openEventIds = [], ?string $eventCursor = null): Channel
    {
        $cfg = ['baseUrl' => 'https://monitoring1.example.test'];
        if ($hostGroup !== null) {
            $cfg['hostGroup'] = $hostGroup;
        }
        if ($openEventIds !== []) {
            $cfg['openEventIds'] = $openEventIds;
        }
        if ($eventCursor !== null) {
            $cfg['eventCursor'] = $eventCursor;
        }

        return (new Channel())
            ->setName('Zabbix')
            ->setAdapterCode(ZabbixAdapter::CODE)
            ->setWorkspace(new Workspace())
            ->setInboundConfig($cfg)
            ->setAuthConfig(['token' => 'test-token']);
    }

    /** @return array<string, mixed> */
    private function problem(string $eventId, string $triggerId, string $name, string $severity, string $clock): array
    {
        return [
            'eventid' => $eventId,
            'objectid' => $triggerId,
            'name' => $name,
            'severity' => $severity,
            'clock' => $clock,
            'r_eventid' => '0',
            'acknowledged' => '0',
            'suppressed' => '0',
            'opdata' => '',
            'tags' => [['tag' => 'class', 'value' => 'os']],
        ];
    }

    /** @return array<string, mixed> */
    private function trigger(string $triggerId, string $hostId, string $host, string $visibleName): array
    {
        return [
            'triggerid' => $triggerId,
            'description' => 'trigger',
            'hosts' => [['hostid' => $hostId, 'host' => $host, 'name' => $visibleName]],
        ];
    }
}
