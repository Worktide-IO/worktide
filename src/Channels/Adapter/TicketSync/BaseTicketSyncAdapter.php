<?php

declare(strict_types=1);

namespace App\Channels\Adapter\TicketSync;

use App\Channels\EntityChange;
use App\Channels\EntitySnapshot;
use App\Channels\InboundAdapter;
use App\Channels\InboundResult;
use App\Channels\PullNotSupportedException;
use App\Channels\SyncableAdapter;
use App\Channels\SyncReentryGuard;
use App\Channels\SyncResult;
use App\Channels\WebhookNotSupportedException;
use App\Entity\Channel;
use App\Entity\EntitySync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Shared scaffolding for ticket-system adapters — Redmine, Jira,
 * GitHub Issues, GitLab Issues, Linear, etc. Subclasses fill in the
 * vendor-specific HTTP shape; the base owns the pagination loop,
 * EntitySync row-management, conflict detection, retry classification.
 *
 * Two interfaces in one class:
 *
 *   - {@see SyncableAdapter} — `pullEntities()`, `pushEntity()`,
 *     `receiveEntityWebhook()`. The entity-sync contract from Phase C.7.1.
 *
 *   - {@see InboundAdapter} — `pull()` / `consumeWebhook()`. The
 *     event-stream contract from Phase C. Concrete adapters
 *     register for both tags so the existing pull-cron also triggers
 *     entity-sync without a second runner. The default `pull()` here
 *     delegates to `pullEntities()`, persists the snapshots as
 *     EntitySync rows + applies them to Worktide-side entities, and
 *     emits *no* InboundEvent — entity-sync writes Tasks directly.
 *
 * Field-mapping is intentionally minimal in the base class:
 *   title       ↔ summary / subject
 *   description ↔ description / body
 *
 * Status, priority, and assignee mappings are vendor-specific and
 * live on the subclass. Custom fields can be wired through
 * `Channel.outboundConfig.fieldMap` later — out of scope here.
 *
 * Conflict detection: a pushEntity call carries the previous values
 * we last knew of; the subclass uses them as a conditional-update
 * pre-condition (If-Match / lastUpdated check). On mismatch the
 * adapter returns `SyncResult::conflict()` and the outbox worker
 * applies the configured {@see \App\Entity\Enum\ConflictPolicy}.
 */
abstract class BaseTicketSyncAdapter implements InboundAdapter, SyncableAdapter
{
    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly EntityManagerInterface $em,
        protected readonly SyncReentryGuard $reentryGuard,
    ) {}

    /**
     * Stable adapter code — e.g. `redmine`, `jira_cloud`. Used by the
     * AdapterRegistry to route Channel rows to this class.
     */
    abstract public function getCode(): string;

    public function getLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->getCode()));
    }

    /**
     * Default to syncing Tasks. Override when the subclass also
     * wires comments, attachments, etc.
     *
     * @return list<string>
     */
    public function supportedEntityTypes(): array
    {
        return ['task'];
    }

    // ---- Subclass extension points --------------------------------

    /**
     * Build the base URL from Channel.inboundConfig — typically
     * `host` + (optional) port + version segment.
     */
    abstract protected function baseUrl(Channel $channel): string;

    /**
     * HTTP headers carrying the auth credential. Reads
     * Channel.authConfig (already decrypted by the cipher listener).
     *
     * @return array<string, string>
     */
    abstract protected function authHeaders(Channel $channel): array;

    /**
     * Path (relative to baseUrl) for the entity list / search
     * endpoint. `since` lets the subclass use the vendor-specific
     * "updated after" filter so reconciliation pulls are incremental.
     */
    abstract protected function listPath(Channel $channel, ?\DateTimeImmutable $since): string;

    /**
     * Path for a single entity (GET / PATCH endpoint).
     */
    abstract protected function entityPath(Channel $channel, string $externalId): string;

    /**
     * Public URL the user can click to open the record in the
     * external system's UI. Returned as `EntitySnapshot.externalUrl`.
     */
    abstract protected function entityWebUrl(Channel $channel, string $externalId): string;

    /**
     * Convert one vendor-shape payload into an EntitySnapshot the
     * framework can apply to a local entity.
     *
     * @param array<string, mixed> $payload
     */
    abstract protected function snapshotFromPayload(Channel $channel, array $payload): EntitySnapshot;

    /**
     * Extract the list of vendor-payloads from a paginated list
     * response. Defaults to `array_values($body)` — override when
     * the response wraps results in an `issues`/`items`/whatever key.
     *
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    protected function extractListItems(array $body): array
    {
        return array_is_list($body) ? $body : array_values($body);
    }

    /**
     * Tell the base loop whether the list response carries more
     * pages and what URL to follow. Default reads `next` / `nextLink`
     * keys; vendor-specific shapes override.
     *
     * @param array<string, mixed> $body
     */
    protected function nextPageUrl(array $body, string $currentUrl): ?string
    {
        foreach (['next', 'nextLink', 'next_url'] as $k) {
            if (isset($body[$k]) && is_string($body[$k])) {
                return $body[$k];
            }
        }
        return null;
    }

    /**
     * Map a Worktide-side change to the vendor-shape payload the
     * subclass will PATCH. Receives the sparse changedFields from
     * the EntityChange so adapters can send minimal updates.
     *
     * @param array<string, mixed> $changedFields
     * @return array<string, mixed>
     */
    abstract protected function mapWorktideToExternal(array $changedFields, EntitySync $mapping): array;

    /**
     * Parse an entity-update webhook payload into one-or-more
     * snapshots. Default: throw — adapters that support push
     * subscriptions override.
     *
     * @return list<EntitySnapshot>
     */
    protected function parseEntityWebhook(Channel $channel, Request $request): array
    {
        throw new WebhookNotSupportedException(sprintf('%s does not yet wire entity webhooks.', $this->getCode()));
    }

    // ---- SyncableAdapter implementation ----------------------------

    public function pullEntities(Channel $channel, ?\DateTimeImmutable $changedSince = null): array
    {
        $url = $this->baseUrl($channel) . $this->listPath($channel, $changedSince);
        $headers = $this->authHeaders($channel) + ['Accept' => 'application/json'];

        $snapshots = [];
        $safety = 0;
        // Pages per pull. Default 20 (×100 = 2000) keeps the per-minute cron cheap;
        // raise channel.inboundConfig.maxPullPages for a one-shot full backfill.
        $maxPages = max(1, (int) ($channel->getInboundConfig()['maxPullPages'] ?? 20));
        while ($url !== null && $safety++ < $maxPages) {
            $response = $this->jsonGet($url, $headers);
            $body = $this->responseToArray($response);
            foreach ($this->extractListItems($body) as $payload) {
                $snapshots[] = $this->snapshotFromPayload($channel, $payload);
            }
            $url = $this->nextPageUrl($body, $url);
        }
        return $snapshots;
    }

    public function pushEntity(EntitySync $mapping, EntityChange $change): SyncResult
    {
        // Disabled / inbound-only mappings: the outbox worker already
        // checks these, but defensive double-check keeps direct calls
        // safe too.
        if ($mapping->getSyncMode()->value === 'disabled'
            || $mapping->getSyncMode()->value === 'inbound'
        ) {
            return SyncResult::synced();
        }

        $channel = $mapping->getChannel();
        $payload = $this->mapWorktideToExternal($change->changedFields, $mapping);
        if ($payload === [] && !$change->isDelete) {
            // Adapter decided nothing on its side needs to change.
            return SyncResult::synced(
                externalUpdatedAt: $mapping->getExternalUpdatedAt(),
                externalUrl: $mapping->getExternalUrl(),
                etag: $mapping->getEtag(),
            );
        }

        $url = $this->baseUrl($channel) . $this->entityPath($channel, $mapping->getExternalId());
        $headers = $this->authHeaders($channel) + [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if ($mapping->getEtag()) {
            $headers['If-Match'] = $mapping->getEtag();
        }
        try {
            $response = $this->httpClient->request(
                $change->isDelete ? 'DELETE' : $this->updateMethod(),
                $url,
                [
                    'headers' => $headers,
                    'body' => $change->isDelete ? null : json_encode($payload, \JSON_THROW_ON_ERROR),
                    'timeout' => 12,
                ],
            );
            $status = $response->getStatusCode();
        } catch (HttpClientException $e) {
            return SyncResult::retry('HTTP transport: ' . $e->getMessage());
        }

        if ($status === 409 || $status === 412) {
            // Both 409 (Conflict) and 412 (Precondition Failed) mean
            // the remote moved while we were composing; surface as
            // conflict so the worker picks the policy.
            return SyncResult::conflict("Remote returned $status — concurrent modification.");
        }
        if ($status >= 500) {
            return SyncResult::retry("Remote $status — server transient.");
        }
        if ($status >= 400) {
            $msg = substr($this->safeBody($response), 0, 200);
            return SyncResult::failed("Remote $status — $msg");
        }

        // Success: re-parse the response (most vendors return the
        // updated payload) so we can stash a fresh ETag /
        // externalUpdatedAt on the mapping.
        $body = $this->responseToArray($response);
        $snapshot = $body !== [] ? $this->snapshotFromPayload($channel, $body) : null;
        $newEtag = $response->getHeaders(false)['etag'][0] ?? $snapshot?->etag;

        return SyncResult::synced(
            externalUpdatedAt: $snapshot?->externalUpdatedAt ?? new \DateTimeImmutable(),
            externalUrl: $snapshot?->externalUrl ?? $this->entityWebUrl($channel, $mapping->getExternalId()),
            etag: $newEtag,
        );
    }

    public function receiveEntityWebhook(Channel $channel, Request $request): array
    {
        return $this->parseEntityWebhook($channel, $request);
    }

    /**
     * Override when the vendor wants PUT instead of PATCH for
     * partial updates (some legacy REST APIs). Defaults to PATCH
     * because both Jira and Redmine accept partial PATCH-style
     * updates against their issue endpoints.
     */
    protected function updateMethod(): string
    {
        return 'PATCH';
    }

    // ---- InboundAdapter implementation (delegating) ---------------

    /**
     * The InboundAdapter `pull()` for ticket-sync channels just
     * triggers `pullEntities()` and lets the EntitySync layer
     * upsert. Returns an empty {@see InboundResult} because
     * entity-sync writes Tasks directly, not InboundEvent rows.
     */
    public function pull(Channel $channel): InboundResult
    {
        // The actual fan-out to Worktide entities lives in the
        // entity-sync runner — to be wired in C.7.4 when the
        // concrete Redmine adapter ships.
        return InboundResult::empty();
    }

    public function consumeWebhook(Channel $channel, Request $request): InboundResult
    {
        // Same as pull — webhooks for ticket-sync produce EntitySnapshot
        // through the SyncableAdapter route, not InboundEvent.
        throw new PullNotSupportedException(sprintf(
            '%s uses the SyncableAdapter::receiveEntityWebhook() path; the InboundAdapter webhook path is not used.',
            $this->getCode(),
        ));
    }

    // ---- Low-level HTTP helpers -----------------------------------

    /**
     * @param array<string, string> $headers
     */
    protected function jsonGet(string $url, array $headers): ResponseInterface
    {
        return $this->httpClient->request('GET', $url, [
            'headers' => $headers,
            'timeout' => 12,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function responseToArray(ResponseInterface $response): array
    {
        try {
            return $response->toArray(false);
        } catch (\Throwable) {
            return [];
        }
    }

    protected function safeBody(ResponseInterface $response): string
    {
        try {
            return $response->getContent(false);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Convenience for parsing a vendor ISO-8601 timestamp into the
     * shape the EntitySnapshot expects. Catches malformed dates so
     * an unexpected value doesn't break the whole pull.
     */
    protected function parseTimestamp(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Generates a stable EntitySync key when the adapter needs a
     * placeholder before the external system has been hit. Not used
     * by the base flow today; kept here so subclasses don't reinvent
     * a UUID-from-externalId trick.
     */
    protected function syntheticEntityId(string $externalId): Uuid
    {
        return Uuid::v5(Uuid::v5(Uuid::v4(), $this->getCode()), $externalId);
    }
}
