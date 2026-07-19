<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Zabbix;

use App\Channels\InboundAdapter;
use App\Channels\InboundResult;
use App\Channels\Testable;
use App\Channels\TestResult;
use App\Channels\WebhookNotSupportedException;
use App\Entity\Channel;
use App\Entity\InboundEvent;
use App\Http\OutboundUrlGuard;
use App\Http\UnsafeUrlException;
use App\Repository\InboundEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pull-based inbound adapter for Zabbix (JSON-RPC API, tested against
 * Zabbix 7.4.7 at monitoring1.wappler.systems).
 *
 * Replaces the fragile "parse monitoring e-mails via IMAP" approach: instead
 * of a mailbox, the worktide:channel:pull cron polls `problem.get` and turns
 * each active problem into an {@see InboundEvent}. The {@see ZabbixThreader}
 * groups them per host+trigger into a Conversation and closes the thread when
 * the problem recovers.
 *
 * Channel.inboundConfig shape:
 *   {
 *     baseUrl:   string,          // Zabbix frontend URL, e.g. "https://monitoring1.wappler.systems"
 *     hostGroup?: string,         // optional: narrow to one host-group name (e.g. "WapplerSystems")
 *     openEventIds?: list<string>,// adapter state — eventids currently in PROBLEM state (managed here)
 *   }
 *
 * Channel.authConfig shape (libsodium-encrypted at rest by
 * {@see \App\EventSubscriber\ChannelAuthConfigCipherListener}):
 *   { token: string }             // Zabbix API token, sent as "Authorization: Bearer <token>"
 *
 * ## Why stateful recovery detection instead of an eventid cursor
 *
 * When a problem recovers, `problem.get` keeps the SAME `eventid` and merely
 * populates `r_eventid`/`r_clock`. Dedup on `eventid` would therefore swallow
 * the recovery, and a plain `eventid_from` cursor would exclude an older
 * still-open problem once a newer problem advanced the cursor — so its recovery
 * would never be seen. Instead the adapter fetches the CURRENT problem set each
 * run (a bounded list) and diffs it against `inboundConfig.openEventIds`:
 *   - eventid new (not yet an InboundEvent)      → emit a "problem raised" event
 *   - eventid previously open but now gone        → emit a "problem resolved" event
 * The recovered event carries a distinct externalId ("resolved:<eventid>") so it
 * dedupes independently of the raise.
 */
final class ZabbixAdapter implements InboundAdapter, Testable
{
    public const CODE = 'zabbix';

    /** externalId prefix for a synthesised recovery event. */
    private const RESOLVED_PREFIX = 'resolved:';

    /** Zabbix severity int → human label. */
    private const SEVERITY = [
        0 => 'Nicht klassifiziert',
        1 => 'Information',
        2 => 'Warnung',
        3 => 'Durchschnittlich',
        4 => 'Hoch',
        5 => 'Desaster',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly InboundEventRepository $events,
    ) {}

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Zabbix';
    }

    public function pull(Channel $channel): InboundResult
    {
        $base = $this->baseUrl($channel);
        if ($base === '') {
            throw new \RuntimeException('Zabbix baseUrl fehlt in der Channel-Konfiguration.');
        }
        OutboundUrlGuard::ensureNotReservedHost($base);

        $token = $this->token($channel);
        if ($token === '') {
            throw new \RuntimeException('Zabbix API-Token fehlt in der Channel-Auth-Konfiguration.');
        }

        $cfg = $channel->getInboundConfig();
        $previousOpen = $this->normaliseIdList($cfg['openEventIds'] ?? []);
        $groupIds = $this->resolveGroupIds($base, $token, $cfg['hostGroup'] ?? null);

        // Current PROBLEM-state problems only (recent:false is the default) —
        // a bounded set we re-fetch every run to diff for recoveries.
        $params = [
            'output' => 'extend',
            'sortfield' => ['eventid'],
            'sortorder' => 'ASC',
            'selectTags' => 'extend',
            'selectAcknowledges' => 'extend',
            'limit' => 500,
        ];
        if ($groupIds !== null) {
            $params['groupids'] = $groupIds;
        }
        $problems = $this->asList($this->call($base, $token, 'problem.get', $params));

        // Resolve the host per trigger (objectid) in one trigger.get for the batch.
        $triggerIds = $this->collectTriggerIds($problems);
        $hostByTrigger = $triggerIds !== [] ? $this->resolveHosts($base, $token, $triggerIds) : [];

        $newEvents = [];
        $currentOpen = [];
        foreach ($problems as $p) {
            $eventId = (string) ($p['eventid'] ?? '');
            if ($eventId === '') {
                continue;
            }
            $currentOpen[] = $eventId;

            if ($this->events->findByExternalId($channel, $eventId) !== null) {
                continue; // already ingested this raise
            }
            $newEvents[] = $this->buildRaiseEvent($channel, $base, $p, $hostByTrigger);
        }

        // Recoveries: previously-open eventids no longer in the current set.
        foreach (array_diff($previousOpen, $currentOpen) as $recoveredId) {
            $recovery = $this->buildRecoveryEvent($channel, $recoveredId);
            if ($recovery !== null) {
                $newEvents[] = $recovery;
            }
        }

        foreach ($newEvents as $event) {
            $this->em->persist($event);
        }

        // Persist adapter state directly on the channel — the runner reads the
        // (mutated) inboundConfig after pull() returns and flushes it.
        $cfg['openEventIds'] = array_values($currentOpen);
        $channel->setInboundConfig($cfg);

        return new InboundResult($newEvents, null);
    }

    public function consumeWebhook(Channel $channel, Request $request): InboundResult
    {
        throw new WebhookNotSupportedException(
            'zabbix is pull-only; the worktide:channel:pull cron polls the Zabbix API.'
        );
    }

    public function selfTest(Channel $channel): TestResult
    {
        $base = $this->baseUrl($channel);
        if ($base === '') {
            return TestResult::failed('Base-URL fehlt in der Channel-Konfiguration.');
        }
        try {
            OutboundUrlGuard::ensureNotReservedHost($base);
        } catch (UnsafeUrlException $e) {
            return TestResult::failed($e->getMessage());
        }
        $token = $this->token($channel);
        if ($token === '') {
            return TestResult::failed('API-Token fehlt in der Channel-Auth-Konfiguration.');
        }
        try {
            // apiinfo.version needs no auth → proves endpoint + path are correct.
            $version = $this->call($base, null, 'apiinfo.version', []);
            // host.get with the token → proves the token authenticates.
            $this->call($base, $token, 'host.get', ['output' => ['hostid'], 'limit' => 1]);

            return TestResult::ok(
                sprintf('Verbunden mit Zabbix %s.', \is_string($version) ? $version : '?'),
                ['version' => \is_string($version) ? $version : null],
            );
        } catch (\Throwable $e) {
            return TestResult::failed('Zabbix nicht erreichbar oder Token ungültig: ' . $e->getMessage());
        }
    }

    // ---- event building -------------------------------------------

    /**
     * @param array<string, mixed>                        $problem
     * @param array<string, array{hostid:string,host:string,name:string}> $hostByTrigger  triggerId → host
     */
    private function buildRaiseEvent(Channel $channel, string $base, array $problem, array $hostByTrigger): InboundEvent
    {
        $eventId = (string) ($problem['eventid'] ?? '');
        $triggerId = (string) ($problem['objectid'] ?? '');
        $host = $hostByTrigger[$triggerId] ?? ['hostid' => '', 'host' => '', 'name' => ''];
        $severity = (int) ($problem['severity'] ?? 0);
        $name = (string) ($problem['name'] ?? 'Zabbix-Problem');
        $clock = (int) ($problem['clock'] ?? 0);
        $tags = $this->normaliseTags($problem['tags'] ?? []);

        $meta = [
            'hostid' => $host['hostid'],
            'host' => $host['host'],
            'hostVisibleName' => $host['name'],
            'triggerId' => $triggerId,
            'severity' => $severity,
            'severityLabel' => self::SEVERITY[$severity] ?? (string) $severity,
            'clock' => $clock,
            'acknowledged' => (string) ($problem['acknowledged'] ?? '0') === '1',
            'suppressed' => (string) ($problem['suppressed'] ?? '0') === '1',
            'opdata' => (string) ($problem['opdata'] ?? ''),
            'tags' => $tags,
            'resolved' => false,
        ];

        $event = (new InboundEvent())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setExternalId($eventId)
            ->setSenderRaw($host['name'] !== '' ? mb_substr($host['name'], 0, 200) : 'Zabbix')
            ->setSubject(mb_substr($name, 0, 250))
            ->setBody($this->renderBody($host, $severity, $clock, $problem, $tags, resolved: false))
            ->setSourceMetadata($meta)
            ->setTraceUrl($this->traceUrl($base, $triggerId, $eventId));

        if ($clock > 0) {
            $event->setReceivedAt((new \DateTimeImmutable())->setTimestamp($clock));
        }

        return $event;
    }

    /**
     * Synthesise a recovery event from the original raise event's metadata so it
     * threads onto the same host+trigger Conversation. Returns null when the
     * original raise was never ingested (nothing to thread onto).
     */
    private function buildRecoveryEvent(Channel $channel, string $eventId): ?InboundEvent
    {
        if ($this->events->findByExternalId($channel, self::RESOLVED_PREFIX . $eventId) !== null) {
            return null; // recovery already emitted
        }
        $origin = $this->events->findByExternalId($channel, $eventId);
        if ($origin === null) {
            return null; // no raise on record → can't determine host+trigger
        }
        $meta = $origin->getSourceMetadata();
        $meta['resolved'] = true;
        $meta['originalEventId'] = $eventId;

        return (new InboundEvent())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setExternalId(self::RESOLVED_PREFIX . $eventId)
            ->setSenderRaw($origin->getSenderRaw() ?? 'Zabbix')
            ->setSubject(mb_substr('Behoben: ' . (string) $origin->getSubject(), 0, 250))
            ->setBody('Problem behoben (Recovery). Ursprüngliches Ereignis: ' . $eventId . '.')
            ->setSourceMetadata($meta)
            ->setTraceUrl($origin->getTraceUrl());
    }

    /**
     * @param array{hostid:string,host:string,name:string} $host
     * @param array<string, mixed>                          $problem
     * @param list<array{tag:string,value:string}>          $tags
     */
    private function renderBody(array $host, int $severity, int $clock, array $problem, array $tags, bool $resolved): string
    {
        $lines = [];
        $lines[] = sprintf('Host: %s%s', $host['name'] !== '' ? $host['name'] : '(unbekannt)', $host['host'] !== '' && $host['host'] !== $host['name'] ? ' (' . $host['host'] . ')' : '');
        $lines[] = 'Schweregrad: ' . (self::SEVERITY[$severity] ?? (string) $severity);
        if ($clock > 0) {
            $lines[] = 'Zeit: ' . (new \DateTimeImmutable())->setTimestamp($clock)->format('Y-m-d H:i:s');
        }
        $opdata = (string) ($problem['opdata'] ?? '');
        if ($opdata !== '') {
            $lines[] = 'Betriebsdaten: ' . $opdata;
        }
        if ($tags !== []) {
            $lines[] = 'Tags: ' . implode(', ', array_map(
                static fn (array $t): string => $t['value'] !== '' ? $t['tag'] . '=' . $t['value'] : $t['tag'],
                $tags,
            ));
        }

        return implode("\n", $lines);
    }

    private function traceUrl(string $base, string $triggerId, string $eventId): ?string
    {
        if ($eventId === '') {
            return null;
        }
        $root = $this->frontendRoot($base);

        return mb_substr(sprintf('%s/tr_events.php?triggerid=%s&eventid=%s', $root, $triggerId, $eventId), 0, 500);
    }

    // ---- Zabbix API helpers ---------------------------------------

    /**
     * @return list<string> resolved group ids, or null when no hostGroup filter is configured
     */
    private function resolveGroupIds(string $base, string $token, mixed $hostGroup): ?array
    {
        if (!\is_string($hostGroup) || trim($hostGroup) === '') {
            return null;
        }
        $groups = $this->asList($this->call($base, $token, 'hostgroup.get', [
            'output' => ['groupid'],
            'filter' => ['name' => [trim($hostGroup)]],
        ]));
        $ids = array_values(array_filter(array_map(
            static fn (array $g): string => (string) ($g['groupid'] ?? ''),
            $groups,
        ), static fn (string $id): bool => $id !== ''));

        // Configured but unknown group → don't silently return the whole instance;
        // filter to nothing so a typo is visible as "0 events" rather than a flood.
        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $problems
     * @return list<string>
     */
    private function collectTriggerIds(array $problems): array
    {
        $ids = array_map(static fn (array $p): string => (string) ($p['objectid'] ?? ''), $problems);

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
    }

    /**
     * @param list<string> $triggerIds
     * @return array<string, array{hostid:string,host:string,name:string}> triggerId → host
     */
    private function resolveHosts(string $base, string $token, array $triggerIds): array
    {
        $triggers = $this->asList($this->call($base, $token, 'trigger.get', [
            'triggerids' => $triggerIds,
            'output' => ['triggerid', 'description'],
            'selectHosts' => ['hostid', 'host', 'name'],
        ]));

        $map = [];
        foreach ($triggers as $t) {
            $triggerId = (string) ($t['triggerid'] ?? '');
            $host = \is_array($t['hosts'] ?? null) ? ($t['hosts'][0] ?? null) : null;
            if ($triggerId === '' || !\is_array($host)) {
                continue;
            }
            $map[$triggerId] = [
                'hostid' => (string) ($host['hostid'] ?? ''),
                'host' => (string) ($host['host'] ?? ''),
                'name' => (string) ($host['name'] ?? ($host['host'] ?? '')),
            ];
        }

        return $map;
    }

    /**
     * One JSON-RPC round-trip. Returns the decoded `result` (mixed — an array for
     * *.get, a string for apiinfo.version). Throws on a Zabbix `error` envelope.
     *
     * @param array<string, mixed> $params
     */
    private function call(string $base, ?string $token, string $method, array $params): mixed
    {
        $headers = ['Content-Type' => 'application/json-rpc'];
        if ($token !== null && $token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = $this->httpClient->request('POST', $this->endpoint($base), [
            'headers' => $headers,
            // Force an empty params to encode as {} not [] — Zabbix rejects a
            // missing/array params on parameterless methods (apiinfo.version).
            'json' => [
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $params === [] ? new \ArrayObject() : $params,
                'id' => 1,
            ],
            'timeout' => 20,
            'max_redirects' => 0,
        ]);

        $data = $response->toArray(false);
        if (isset($data['error']) && \is_array($data['error'])) {
            throw new \RuntimeException(sprintf(
                'Zabbix %s: %s %s',
                $method,
                (string) ($data['error']['message'] ?? ''),
                (string) ($data['error']['data'] ?? ''),
            ));
        }

        return $data['result'] ?? null;
    }

    // ---- config accessors + small utilities -----------------------

    private function baseUrl(Channel $channel): string
    {
        $url = $channel->getInboundConfig()['baseUrl'] ?? '';

        return \is_string($url) ? rtrim(trim($url), '/') : '';
    }

    private function token(Channel $channel): string
    {
        // May remain an ['enc' => …] array if the cipher key can't decrypt it —
        // guard so a rotated key fails cleanly (401) instead of a type error.
        $token = $channel->getAuthConfig()['token'] ?? '';

        return \is_string($token) ? trim($token) : '';
    }

    private function endpoint(string $base): string
    {
        $e = rtrim($base, '/');
        if (str_ends_with($e, '/api_jsonrpc.php')) {
            return $e;
        }
        if (str_ends_with($e, '/zabbix')) {
            return $e . '/api_jsonrpc.php';
        }

        return $e . '/zabbix/api_jsonrpc.php';
    }

    private function frontendRoot(string $base): string
    {
        $e = rtrim($base, '/');
        if (str_ends_with($e, '/api_jsonrpc.php')) {
            return substr($e, 0, -\strlen('/api_jsonrpc.php'));
        }
        if (str_ends_with($e, '/zabbix')) {
            return $e;
        }

        return $e . '/zabbix';
    }

    /**
     * @param mixed $result
     * @return list<array<string, mixed>>
     */
    private function asList(mixed $result): array
    {
        if (!\is_array($result)) {
            return [];
        }

        return array_values(array_filter($result, 'is_array'));
    }

    /**
     * @param mixed $tags
     * @return list<array{tag:string,value:string}>
     */
    private function normaliseTags(mixed $tags): array
    {
        if (!\is_array($tags)) {
            return [];
        }
        $out = [];
        foreach ($tags as $t) {
            if (!\is_array($t)) {
                continue;
            }
            $out[] = [
                'tag' => (string) ($t['tag'] ?? ''),
                'value' => (string) ($t['value'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param mixed $ids
     * @return list<string>
     */
    private function normaliseIdList(mixed $ids): array
    {
        if (!\is_array($ids)) {
            return [];
        }
        $out = array_map(static fn ($v): string => (string) $v, array_filter($ids, 'is_scalar'));

        return array_values(array_unique($out));
    }
}
