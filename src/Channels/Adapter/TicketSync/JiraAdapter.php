<?php

declare(strict_types=1);

namespace App\Channels\Adapter\TicketSync;

use App\Channels\EntityApplier;
use App\Channels\EntitySnapshot;
use App\Channels\ExternalParticipant;
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
 * Bidirectional sync adapter for Jira (Server / Data Center + Cloud).
 *
 * Tested against `jiraweb.linear.eu` with the user's PAT (the
 * jiraweb.linear.eu memo). The same code path handles Cloud — the
 * only difference is the auth header shape.
 *
 * Channel.inboundConfig shape:
 *   {
 *     baseUrl: string,             // e.g. "https://jiraweb.linear.eu"
 *     apiVersion?: "2" | "3",      // default "2" (Server). Use "3" for Cloud.
 *     projectKey?: string,         // narrow to one Jira project (e.g. "LW")
 *     jqlFilter?: string,          // extra JQL appended to the project filter
 *   }
 *
 * Channel.authConfig shape (libsodium-encrypted at rest):
 *   Either { personalAccessToken: string }  — Server / DC
 *   Or     { email: string, apiToken: string }  — Cloud
 *
 * Field mapping (V1, same minimal shape as Redmine):
 *   Worktide.title       ↔ Jira.fields.summary
 *   Worktide.description ↔ Jira.fields.description
 *
 * Description on Jira Cloud (apiVersion 3) is ADF — a nested JSON
 * document tree. Server (apiVersion 2) takes plain text/wiki. The
 * adapter sends a plain string either way; Cloud will silently
 * coerce simple strings into a trivial ADF paragraph wrapper for
 * V1 (good enough for round-tripping our title+text-only sync).
 *
 * Out of scope V1 (every entry is its own Phase-D follow-up):
 *   - JQL-aware status transitions (Jira requires a transition,
 *     not a direct status setter)
 *   - Custom fields (need FieldMap config per channel)
 *   - Assignee sync (Jira accountId ↔ Worktide UUID needs UserSync)
 *   - Comments + worklog round-trip
 *   - Webhooks (Jira admin-configurable; arrives separately when
 *     the operator wires the push subscription)
 */
final class JiraAdapter extends BaseTicketSyncAdapter implements Testable
{
    public const CODE = 'jira';

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
        return 'Jira';
    }

    // ---- vendor-specific URL + auth shape -------------------------

    protected function baseUrl(Channel $channel): string
    {
        $url = (string) ($channel->getInboundConfig()['baseUrl'] ?? '');
        return rtrim($url, '/');
    }

    protected function authHeaders(Channel $channel): array
    {
        $auth = $channel->getAuthConfig();
        // PAT (Server/DC) wins over email+token (Cloud); only one
        // should be configured. Operator picks at channel-create time
        // — the SPA wizard exposes the right fields per apiVersion.
        $pat = (string) ($auth['personalAccessToken'] ?? '');
        if ($pat !== '') {
            return ['Authorization' => 'Bearer ' . $pat];
        }
        $email = (string) ($auth['email'] ?? '');
        $apiToken = (string) ($auth['apiToken'] ?? '');
        if ($email !== '' && $apiToken !== '') {
            return ['Authorization' => 'Basic ' . base64_encode("$email:$apiToken")];
        }
        return [];
    }

    private function apiVersion(Channel $channel): string
    {
        $v = (string) ($channel->getInboundConfig()['apiVersion'] ?? '2');
        return \in_array($v, ['2', '3'], true) ? $v : '2';
    }

    protected function listPath(Channel $channel, ?\DateTimeImmutable $since): string
    {
        $cfg = $channel->getInboundConfig();
        $clauses = [];
        if (!empty($cfg['projectKey'])) {
            $clauses[] = sprintf('project = "%s"', $this->escapeJqlString((string) $cfg['projectKey']));
        }
        if (!empty($cfg['jqlFilter'])) {
            $clauses[] = '(' . (string) $cfg['jqlFilter'] . ')';
        }
        if ($since !== null) {
            // Jira JQL accepts "updated >= '2026-06-17 00:00'" — date-
            // granularity matches our 60s pull cadence.
            $clauses[] = sprintf('updated >= "%s"', $since->format('Y-m-d H:i'));
        }
        $jql = implode(' AND ', $clauses);
        $jql = $jql === '' ? 'order by updated DESC' : $jql . ' order by updated DESC';

        $params = [
            'jql' => $jql,
            'startAt' => 0,
            'maxResults' => 100,
            // Restrict to the fields we actually map so the response
            // stays small; comments/worklog land when we extend.
            'fields' => 'summary,description,updated,status,priority,assignee,project',
        ];
        return sprintf('/rest/api/%s/search?', $this->apiVersion($channel)) . http_build_query($params);
    }

    protected function entityPath(Channel $channel, string $externalId): string
    {
        return sprintf('/rest/api/%s/issue/%s', $this->apiVersion($channel), rawurlencode($externalId));
    }

    protected function entityWebUrl(Channel $channel, string $externalId): string
    {
        return sprintf('%s/browse/%s', $this->baseUrl($channel), rawurlencode($externalId));
    }

    /**
     * Jira's search response shape is
     * `{startAt, maxResults, total, issues: [...]}`. Override
     * extractListItems to unwrap.
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
     * Derive next-page URL from startAt + maxResults + total since
     * Jira doesn't return a Link header.
     *
     * @param array<string, mixed> $body
     */
    protected function nextPageUrl(array $body, string $currentUrl): ?string
    {
        $startAt = (int) ($body['startAt'] ?? 0);
        $maxResults = (int) ($body['maxResults'] ?? 0);
        $total = (int) ($body['total'] ?? 0);
        if ($maxResults === 0 || $startAt + $maxResults >= $total) {
            return null;
        }
        $next = $startAt + $maxResults;
        if (preg_match('/(\?|&)startAt=\d+/', $currentUrl)) {
            return preg_replace('/(\?|&)startAt=\d+/', '$1startAt=' . $next, $currentUrl) ?? $currentUrl;
        }
        return $currentUrl . (str_contains($currentUrl, '?') ? '&' : '?') . 'startAt=' . $next;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function snapshotFromPayload(Channel $channel, array $payload): EntitySnapshot
    {
        $externalId = (string) ($payload['key'] ?? $payload['id'] ?? '');
        $fields = $payload['fields'] ?? [];
        $summary = (string) ($fields['summary'] ?? '');
        $description = $this->extractDescription($fields['description'] ?? null);
        $updatedAt = $this->parseTimestamp($fields['updated'] ?? null);

        // Assignee as a participant for the import-filter. Jira often includes
        // `emailAddress` (unless hidden by privacy settings) — pass both id and
        // email so the filter can resolve via explicit mapping OR email match.
        // Watcher list needs a separate API call; that's a follow-up.
        $participants = [];
        $assignee = $fields['assignee'] ?? null;
        if (\is_array($assignee) && ($assignee['accountId'] ?? null) !== null) {
            $participants[] = new ExternalParticipant(
                externalUserId: (string) $assignee['accountId'],
                email: isset($assignee['emailAddress']) ? (string) $assignee['emailAddress'] : null,
                role: ExternalParticipant::ROLE_ASSIGNEE,
            );
        }

        return new EntitySnapshot(
            entityType: 'task',
            externalId: $externalId,
            fields: [
                'title' => $summary,
                'description' => $description !== '' ? $description : null,
            ],
            externalUpdatedAt: $updatedAt,
            externalUrl: $externalId !== '' ? $this->entityWebUrl($channel, $externalId) : null,
            etag: null,  // Jira doesn't expose ETag; rely on `updated` for conflict detection
            sourceMetadata: [
                'jiraIssueId' => $payload['id'] ?? null,
                'jiraProjectKey' => $fields['project']['key'] ?? null,
                'jiraStatusName' => $fields['status']['name'] ?? null,
                'jiraPriorityName' => $fields['priority']['name'] ?? null,
                'jiraAssigneeAccountId' => $fields['assignee']['accountId'] ?? null,
                'lastCheckedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
            remoteDeleted: false,
            participants: $participants,
        );
    }

    /**
     * Jira Cloud (apiVersion 3) returns description as an ADF
     * document (`{type: "doc", content: [...]}`); Server/DC
     * (apiVersion 2) returns a plain string. Coerce both to text
     * — V1 doesn't preserve rich formatting on either side.
     */
    private function extractDescription(mixed $raw): string
    {
        if (is_string($raw)) {
            return $raw;
        }
        if (!is_array($raw) || ($raw['type'] ?? null) !== 'doc') {
            return '';
        }
        // Walk the ADF tree and concatenate every `text` leaf.
        $out = '';
        $stack = $raw['content'] ?? [];
        while ($stack) {
            $node = array_shift($stack);
            if (!is_array($node)) continue;
            if (($node['type'] ?? '') === 'text' && isset($node['text'])) {
                $out .= (string) $node['text'];
            }
            if (($node['type'] ?? '') === 'paragraph') {
                $out .= "\n";
            }
            if (isset($node['content']) && is_array($node['content'])) {
                array_push($stack, ...$node['content']);
            }
        }
        return trim($out);
    }

    /**
     * @param array<string, mixed> $changedFields
     * @return array<string, mixed>
     */
    protected function mapWorktideToExternal(array $changedFields, EntitySync $mapping): array
    {
        if (isset($changedFields['__created'])) {
            // Fresh Worktide entity → don't auto-create on Jira; the
            // initial bind is a manual UI action (C.7.6).
            return [];
        }
        $fields = [];
        if (\array_key_exists('title', $changedFields)) {
            $fields['summary'] = (string) $changedFields['title'];
        }
        if (\array_key_exists('description', $changedFields)) {
            $text = (string) ($changedFields['description'] ?? '');
            // For apiVersion 3 (Cloud) wrap the plain text as a
            // minimal ADF document; Server takes the string straight.
            $apiVersion = $this->apiVersion($mapping->getChannel());
            $fields['description'] = $apiVersion === '3'
                ? [
                    'type' => 'doc',
                    'version' => 1,
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [['type' => 'text', 'text' => $text]],
                    ]],
                ]
                : $text;
        }
        if ($fields === []) {
            return [];
        }
        // Jira PUT /issue/{key} expects {"fields": {...}}.
        return ['fields' => $fields];
    }

    /**
     * Jira returns 204 No Content from PUT — the base handler is
     * fine with that. Override to send PUT explicitly so the base
     * doesn't fall back to PATCH (Jira REST docs are unambiguous
     * about PUT for partial-field updates).
     */
    protected function updateMethod(): string
    {
        return 'PUT';
    }

    /**
     * Mirror RedmineAdapter::pull — wrap pull+apply+flush in the
     * re-entry guard so the listener doesn't enqueue an outbox row
     * for inbound writes.
     */
    public function pull(Channel $channel): InboundResult
    {
        $cursor = $channel->getInboundConfig()['cursor'] ?? null;
        $since = is_string($cursor) && $cursor !== ''
            ? new \DateTimeImmutable($cursor)
            : null;

        $this->reentryGuard->enter();
        try {
            $snapshots = $this->pullEntities($channel, $since);
            foreach ($snapshots as $s) {
                $this->entityApplier->apply($channel, $s);
            }
            $this->em->flush();
        } finally {
            $this->reentryGuard->leave();
        }

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
        // Entity-sync webhooks → SyncableAdapter::receiveEntityWebhook
        // (the EntityWebhookController calls into that, not here).
        throw new PullNotSupportedException('Jira webhooks dispatch through /v1/inbound/entity-webhooks/, not /v1/inbound/webhooks/.');
    }

    /**
     * Parse a Jira "issue updated" / "issue created" webhook payload.
     *
     * Jira webhook config (Admin → System → WebHooks) lets the
     * operator pick which events fire — we handle the issue-shaped
     * ones (`jira:issue_created`, `jira:issue_updated`,
     * `jira:issue_deleted`). Each carries:
     *
     *   {
     *     "webhookEvent": "jira:issue_updated",
     *     "issue": { "key": "LW-403", "fields": {...} },
     *     "changelog": {...},  // present on updates
     *     "user": {...},
     *     "timestamp": 1234567890
     *   }
     *
     * The `issue.fields` block has the same shape as `/search` results,
     * so we delegate to `snapshotFromPayload()`. For delete events the
     * `issue` still arrives but we set `remoteDeleted=true` so the
     * EntityApplier marks the mapping stale instead of touching the
     * local entity.
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
        $issue = $decoded['issue'] ?? null;
        if (!is_array($issue)) {
            return [];
        }
        $snapshot = $this->snapshotFromPayload($channel, $issue);
        $event = (string) ($decoded['webhookEvent'] ?? '');
        if ($event === 'jira:issue_deleted') {
            // Re-emit with remoteDeleted=true; EntityApplier flips the
            // mapping into stale state without re-creating the local
            // record.
            return [new \App\Channels\EntitySnapshot(
                entityType: $snapshot->entityType,
                externalId: $snapshot->externalId,
                fields: $snapshot->fields,
                externalUpdatedAt: $snapshot->externalUpdatedAt,
                externalUrl: $snapshot->externalUrl,
                etag: $snapshot->etag,
                sourceMetadata: $snapshot->sourceMetadata,
                remoteDeleted: true,
            )];
        }
        return [$snapshot];
    }

    public function selfTest(Channel $channel): TestResult
    {
        $base = $this->baseUrl($channel);
        if ($base === '') {
            return TestResult::failed('Base URL missing in channel config.');
        }
        $headers = $this->authHeaders($channel);
        if (empty($headers)) {
            return TestResult::failed('Auth missing — paste either a PAT (Server/DC) or email + API-token (Cloud).');
        }
        $url = sprintf('%s/rest/api/%s/myself', $base, $this->apiVersion($channel));
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers + ['Accept' => 'application/json'],
                'timeout' => 8,
            ]);
            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                return TestResult::failed('Auth rejected — PAT/token invalid or missing scopes.');
            }
            if ($status >= 400) {
                return TestResult::failed(sprintf(
                    'Jira returned %d: %s',
                    $status,
                    substr($this->safeBody($response), 0, 120),
                ));
            }
            $body = $this->responseToArray($response);
            $label = (string) ($body['displayName'] ?? $body['name'] ?? $body['emailAddress'] ?? '?');
            return TestResult::ok(sprintf('Verbunden als %s.', $label), ['user' => $label]);
        } catch (\Throwable $e) {
            return TestResult::failed('Jira unreachable: ' . $e->getMessage());
        }
    }

    /**
     * Escape a JQL string literal — Jira disallows raw quotes
     * inside double-quoted strings.
     */
    private function escapeJqlString(string $raw): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $raw);
    }
}
