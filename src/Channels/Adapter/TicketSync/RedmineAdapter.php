<?php

declare(strict_types=1);

namespace App\Channels\Adapter\TicketSync;

use App\Channels\EntityApplier;
use App\Channels\EntitySnapshot;
use App\Channels\InboundResult;
use App\Channels\PullNotSupportedException;
use App\Channels\SyncReentryGuard;
use App\Channels\Testable;
use App\Channels\TestResult;
use App\Entity\Channel;
use App\Entity\EntitySync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bidirectional sync adapter for Redmine.
 *
 * Tested against `projects.wappler.systems` (the user's own Redmine
 * instance — see `reference_redmine_ticket_creation.md` memo).
 *
 * Channel.inboundConfig shape:
 *   {
 *     baseUrl: string,        // e.g. "https://projects.wappler.systems"
 *     projectId?: int|string, // narrow to one Redmine project (optional)
 *     extraFilter?: string,   // raw query-string tail (advanced; rare)
 *   }
 *
 * Channel.authConfig shape (libsodium-encrypted at rest):
 *   { apiKey: string }
 *
 * Per the memo: send the token via `X-Redmine-API-Key` header, NEVER
 * as `?key=` query — that ends up in access logs.
 *
 * V1 field-mapping (intentionally minimal — both sides round-trip
 * safely without losing data):
 *   Worktide.title       ↔ Redmine.subject
 *   Worktide.description ↔ Redmine.description
 *
 * Out of scope for V1 (each is a Phase-D follow-up):
 *   - Status sync (needs per-channel status-mapping table, the
 *     workflow editor scope creep otherwise)
 *   - Assignee sync (needs UserSync — Redmine user-id is an int,
 *     Worktide users are UUIDs; need a name/email bridge)
 *   - Comments sync (needs comment-entity sync, which lands when
 *     the EntityTypeResolver gets `comment` wired)
 *   - Custom fields (needs FieldMap config per channel)
 *
 * Webhook: Redmine has the third-party `redmine_webhook` plugin but
 * it's not universal. V1 is pull-only on a 60s cron; webhook arrives
 * in a follow-up when the operator installs the plugin.
 */
final class RedmineAdapter extends BaseTicketSyncAdapter implements Testable
{
    public const CODE = 'redmine';

    public function __construct(
        HttpClientInterface $httpClient,
        EntityManagerInterface $em,
        SyncReentryGuard $reentryGuard,
        private readonly EntityApplier $entityApplier,
    ) {
        parent::__construct($httpClient, $em, $reentryGuard);
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Redmine';
    }

    // ---- vendor-specific URL shape --------------------------------

    protected function baseUrl(Channel $channel): string
    {
        $url = (string) ($channel->getInboundConfig()['baseUrl'] ?? '');
        return rtrim($url, '/');
    }

    protected function authHeaders(Channel $channel): array
    {
        $key = (string) ($channel->getAuthConfig()['apiKey'] ?? '');
        if ($key === '') {
            return [];
        }
        return ['X-Redmine-API-Key' => $key];
    }

    protected function listPath(Channel $channel, ?\DateTimeImmutable $since): string
    {
        $cfg = $channel->getInboundConfig();
        $params = [
            'status_id' => '*',             // include closed issues too
            'sort' => 'updated_on:desc',
            'limit' => 100,
            'offset' => 0,
        ];
        $projectId = $cfg['projectId'] ?? null;
        if ($projectId !== null && $projectId !== '') {
            $params['project_id'] = (string) $projectId;
        }
        if ($since !== null) {
            // Redmine accepts `updated_on=>=YYYY-MM-DD` for incremental
            // pulls. Date granularity is enough for the once-a-minute
            // cadence we run on.
            $params['updated_on'] = '>=' . $since->format('Y-m-d');
        }
        $query = http_build_query($params);
        $extra = (string) ($cfg['extraFilter'] ?? '');
        if ($extra !== '') {
            $query .= '&' . ltrim($extra, '&');
        }
        return '/issues.json?' . $query;
    }

    protected function entityPath(Channel $channel, string $externalId): string
    {
        // include=journals,attachments lets us see comments + files
        // without a second round-trip; subclass extension when those
        // get wired.
        return sprintf('/issues/%s.json', rawurlencode($externalId));
    }

    protected function entityWebUrl(Channel $channel, string $externalId): string
    {
        return sprintf('%s/issues/%s', $this->baseUrl($channel), $externalId);
    }

    /**
     * Redmine wraps the page in `{issues, total_count, offset, limit}`.
     *
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    protected function extractListItems(array $body): array
    {
        $issues = $body['issues'] ?? [];
        return is_array($issues) ? array_values($issues) : [];
    }

    /**
     * No next-link header — derive paging from total_count/offset/limit.
     * Override to follow the convention.
     *
     * @param array<string, mixed> $body
     */
    protected function nextPageUrl(array $body, string $currentUrl): ?string
    {
        $offset = (int) ($body['offset'] ?? 0);
        $limit = (int) ($body['limit'] ?? 0);
        $total = (int) ($body['total_count'] ?? 0);
        if ($limit === 0 || $offset + $limit >= $total) {
            return null;
        }
        // Replace `offset=N` in the existing URL with the next page.
        $nextOffset = $offset + $limit;
        if (preg_match('/(\?|&)offset=\d+/', $currentUrl)) {
            return preg_replace('/(\?|&)offset=\d+/', '$1offset=' . $nextOffset, $currentUrl) ?? $currentUrl;
        }
        return $currentUrl . (str_contains($currentUrl, '?') ? '&' : '?') . 'offset=' . $nextOffset;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function snapshotFromPayload(Channel $channel, array $payload): EntitySnapshot
    {
        // Redmine GET /issues/{id}.json wraps in `{issue: {...}}`;
        // GET /issues.json items are NOT wrapped. Handle both.
        if (isset($payload['issue']) && is_array($payload['issue'])) {
            $payload = $payload['issue'];
        }

        $externalId = (string) ($payload['id'] ?? '');
        $title = (string) ($payload['subject'] ?? '');
        $description = (string) ($payload['description'] ?? '');

        return new EntitySnapshot(
            entityType: 'task',
            externalId: $externalId,
            fields: [
                'title' => $title,
                'description' => $description !== '' ? $description : null,
            ],
            externalUpdatedAt: $this->parseTimestamp($payload['updated_on'] ?? null),
            externalUrl: $externalId !== '' ? $this->entityWebUrl($channel, $externalId) : null,
            etag: null,  // Redmine doesn't expose ETag on issues
            sourceMetadata: [
                'redmineProjectId' => $payload['project']['id'] ?? null,
                'redmineStatusId' => $payload['status']['id'] ?? null,
                'redminePriorityId' => $payload['priority']['id'] ?? null,
                'redmineAssignedToId' => $payload['assigned_to']['id'] ?? null,
                'lastCheckedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
            remoteDeleted: false,
        );
    }

    /**
     * @param array<string, mixed> $changedFields
     * @return array<string, mixed>
     */
    protected function mapWorktideToExternal(array $changedFields, EntitySync $mapping): array
    {
        $issue = [];
        // Skip __created sentinel from postPersist — Redmine doesn't
        // get a "this is a fresh local entity" update; the binding
        // happens via a separate adapter command (out of scope V1).
        if (isset($changedFields['__created'])) {
            return [];
        }
        if (\array_key_exists('title', $changedFields)) {
            $issue['subject'] = (string) $changedFields['title'];
        }
        if (\array_key_exists('description', $changedFields)) {
            $issue['description'] = (string) ($changedFields['description'] ?? '');
        }
        if ($issue === []) {
            return [];
        }
        // Redmine PUT /issues/{id}.json expects {"issue": {...}}.
        return ['issue' => $issue];
    }

    protected function updateMethod(): string
    {
        // Redmine uses PUT for partial updates against /issues/{id}.json.
        // The base default is PATCH; override here.
        return 'PUT';
    }

    /**
     * Override the InboundAdapter::pull() so the existing
     * worktide:channel:pull cron actually applies snapshots.
     */
    public function pull(Channel $channel): InboundResult
    {
        $cursor = $channel->getInboundConfig()['cursor'] ?? null;
        $since = is_string($cursor) && $cursor !== ''
            ? new \DateTimeImmutable($cursor)
            : null;

        // Hold the re-entry guard for the WHOLE pull → apply → flush
        // sequence. EntityApplier::apply() defers the actual Doctrine
        // flush (happens in ChannelPullCommand after this method
        // returns); the listener checks the guard at flush-time so
        // it needs to still be active then. Releasing only around
        // apply() lets the deferred flush escape the guard and
        // ping-pong the value right back to Redmine on the next
        // outbox run — exactly what we saw on the first integration
        // test.
        $this->reentryGuard->enter();
        try {
            $snapshots = $this->pullEntities($channel, $since);
            foreach ($snapshots as $s) {
                $this->entityApplier->apply($channel, $s);
            }
            // Flush inside the guard so the listener sees the guard
            // is held and skips the outbox enqueue for these writes.
            $this->em->flush();
        } finally {
            $this->reentryGuard->leave();
        }

        // Persist a new cursor = max(externalUpdatedAt) across this batch
        // so the next pull only fetches what changed since.
        $newCursor = $since?->format(\DateTimeInterface::ATOM);
        foreach ($snapshots as $s) {
            if ($s->externalUpdatedAt && (!$newCursor || $s->externalUpdatedAt->format(\DateTimeInterface::ATOM) > $newCursor)) {
                $newCursor = $s->externalUpdatedAt->format(\DateTimeInterface::ATOM);
            }
        }
        return new InboundResult([], $newCursor);
    }

    public function consumeWebhook(Channel $channel, Request $request): InboundResult
    {
        // Entity-sync webhooks go through the SyncableAdapter route,
        // not InboundAdapter — that's wired in C.7.7 via
        // EntityWebhookController calling receiveEntityWebhook().
        throw new PullNotSupportedException('Redmine webhooks dispatch through /v1/inbound/entity-webhooks/, not /v1/inbound/webhooks/.');
    }

    /**
     * Parse a payload from the `redmine_webhook` plugin.
     *
     * Plugin sends `{"payload": {"action": "opened"|"updated", "issue":
     * {...}, "url": "...", "user": {...}}}` for create / update events.
     * The `issue` sub-object matches the same shape we already handle
     * in `snapshotFromPayload()` (subject, description, updated_on,
     * project / status / priority / assigned_to nested objects), so we
     * just unwrap and delegate.
     *
     * The plugin doesn't fire on issue deletions, so `remoteDeleted`
     * stays false — staleness is detected later by the reconciliation
     * worker (planned C.7.x).
     *
     * @return list<\App\Channels\EntitySnapshot>
     */
    protected function parseEntityWebhook(Channel $channel, Request $request): array
    {
        $raw = (string) ($request->getContent() ?? '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        // The plugin wraps everything in `payload`; some configs strip
        // the wrapper. Accept both shapes.
        $envelope = $decoded['payload'] ?? $decoded;
        $issue = $envelope['issue'] ?? null;
        if (!is_array($issue)) {
            return [];
        }
        return [$this->snapshotFromPayload($channel, $issue)];
    }

    public function selfTest(Channel $channel): TestResult
    {
        $base = $this->baseUrl($channel);
        if ($base === '') {
            return TestResult::failed('Base URL missing in channel config.');
        }
        if (empty($this->authHeaders($channel))) {
            return TestResult::failed('API key missing in channel auth-config.');
        }
        try {
            $response = $this->httpClient->request('GET', $base . '/users/current.json', [
                'headers' => $this->authHeaders($channel) + ['Accept' => 'application/json'],
                'timeout' => 8,
            ]);
            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                return TestResult::failed('Auth rejected — API key invalid or insufficient permissions.');
            }
            if ($status >= 400) {
                return TestResult::failed(sprintf('Redmine returned %d: %s', $status, substr($this->safeBody($response), 0, 120)));
            }
            $body = $this->responseToArray($response);
            $login = $body['user']['login'] ?? '?';
            return TestResult::ok(sprintf('Verbunden als %s.', $login));
        } catch (\Throwable $e) {
            return TestResult::failed('Redmine unreachable: ' . $e->getMessage());
        }
    }
}
