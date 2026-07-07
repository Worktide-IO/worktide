<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Channels\AdapterRegistry;
use App\Channels\EntityApplier;
use App\Channels\SyncReentryGuard;
use App\Channels\WebhookNotSupportedException;
use App\Entity\Channel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public push endpoint for entity-sync — Jira/Redmine/etc. post
 * here when an issue changes, no Worktide-side cron needed.
 *
 *   POST /v1/inbound/entity-webhooks/{token}
 *
 * Token resolution mirrors the event-stream webhook controller
 * ({@see WebhookIngestController}): each entity-sync-capable
 * channel carries a `webhookToken` in its inboundConfig that IS
 * the credential. No JWT — the third-party admin pastes the URL
 * into their Jira / Redmine webhook config.
 *
 * Differences from the event-stream webhook:
 *   - Routes to `SyncableAdapter::receiveEntityWebhook()` instead
 *     of `InboundAdapter::consumeWebhook()`
 *   - Result is a list of {@see \App\Channels\EntitySnapshot}s
 *     that we hand to {@see EntityApplier} (same path the
 *     scheduled pull uses), so Task records update in-place
 *   - Wrapped in {@see SyncReentryGuard} so the apply doesn't
 *     bounce back through the outbox-recording listener and
 *     re-push to the source
 *
 * Responses:
 *   - 200 with `{applied: N}` on success
 *   - 410 Gone when the channel was disabled / deleted (some
 *     senders honour this to stop retrying)
 *   - 404 Not Found for unknown tokens (same response as a typo
 *     so valid tokens can't be probed)
 */
final class EntityWebhookController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AdapterRegistry $registry,
        private readonly EntityApplier $entityApplier,
        private readonly SyncReentryGuard $reentryGuard,
    ) {}

    #[Route(
        path: '/v1/inbound/entity-webhooks/{token}',
        name: 'api_inbound_entity_webhooks',
        requirements: ['token' => '[A-Za-z0-9_-]{16,128}'],
        methods: ['POST', 'PUT'],
    )]
    public function __invoke(string $token, Request $request): JsonResponse
    {
        $channel = $this->resolveByToken($token);
        if ($channel === null) {
            throw new NotFoundHttpException();
        }
        if (!$channel->isEnabled() || !$channel->isEntitySyncEnabled()) {
            return new JsonResponse(['error' => 'channel_unavailable'], 410);
        }
        $adapter = $this->registry->trySync($channel->getAdapterCode());
        if ($adapter === null) {
            throw new NotFoundHttpException();
        }

        try {
            $snapshots = $adapter->receiveEntityWebhook($channel, $request);
        } catch (WebhookNotSupportedException) {
            throw new NotFoundHttpException();
        }

        // Apply inside the guard so the writes are silent to the
        // outbox-recording listener — without this, an inbound
        // webhook would enqueue an outbound push that races right
        // back to the source.
        $applied = 0;
        $this->reentryGuard->enter();
        try {
            foreach ($snapshots as $snapshot) {
                $mapping = $this->entityApplier->apply($channel, $snapshot);
                if ($mapping !== null) {
                    $applied++;
                }
            }
            $channel->setLastSyncedAt(new \DateTimeImmutable());
            $channel->setLastSyncError(null);
            $this->em->flush();
        } finally {
            $this->reentryGuard->leave();
        }

        return new JsonResponse([
            'applied' => $applied,
            'received' => \count($snapshots),
        ], 200);
    }

    private function resolveByToken(string $token): ?Channel
    {
        // Same scan-and-hash-equals pattern as the event-stream
        // webhook controller — keeps the lookup constant-time
        // resistant + portable across DB engines. Channel count
        // per workspace stays small enough that the linear scan
        // doesn't matter.
        $candidates = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Channel::class, 'c')
            ->where('c.deletedAt IS NULL')
            ->andWhere('c.isEnabled = 1')
            ->getQuery()
            ->getResult();
        foreach ($candidates as $c) {
            if (!$c instanceof Channel) continue;
            $stored = (string) ($c->getInboundConfig()['webhookToken'] ?? '');
            if ($stored !== '' && hash_equals($stored, $token)) {
                return $c;
            }
        }
        return null;
    }
}
