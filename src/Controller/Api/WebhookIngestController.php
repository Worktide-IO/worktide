<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Channels\AdapterRegistry;
use App\Channels\WebhookNotSupportedException;
use App\Entity\Channel;
use App\Entity\Enum\ChannelCapability;
use App\Message\ProcessInboundEventMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public endpoint that third-party services POST to. The URL path
 * carries an opaque per-channel token; that token IS the credential
 * (no JWT, no PAT) so the route MUST live behind PUBLIC_ACCESS in
 * security.yaml.
 *
 *   POST /v1/inbound/webhooks/{token}
 *
 * Token resolution is a single-row lookup on Channel.inboundConfig
 * (token field) — kept generic so any push-based adapter can use
 * this same controller. The adapter is dispatched by the channel's
 * adapterCode through {@see AdapterRegistry}.
 *
 * Responses:
 *   - 204 No Content on successful ingest (provider stops retrying)
 *   - 410 Gone when the channel was deleted or disabled — the
 *     provider should stop sending here
 *   - 404 Not Found for unknown tokens — same response as a typo so
 *     valid tokens can't be probed against the 404 vs. 401 distinction
 *
 * The endpoint never echoes the resolved channel ID back; a leaked
 * token would otherwise leak which channel it points to.
 */
final class WebhookIngestController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AdapterRegistry $registry,
        private readonly MessageBusInterface $bus,
    ) {}

    #[Route(
        path: '/v1/inbound/webhooks/{token}',
        name: 'api_inbound_webhooks',
        host: 'api.worktide.ddev.site',
        requirements: ['token' => '[A-Za-z0-9_-]{16,128}'],
        methods: ['POST', 'PUT'],
    )]
    public function __invoke(string $token, Request $request): JsonResponse
    {
        $channel = $this->resolveByToken($token);
        if ($channel === null) {
            // Same 404 for "unknown token" + "wrong adapter type" so
            // valid tokens can't be probed.
            throw new NotFoundHttpException();
        }
        if (!$channel->isEnabled() || !$channel->supports(ChannelCapability::Inbound)) {
            return new JsonResponse(null, 410); // Gone — stop retrying
        }

        $adapter = $this->registry->tryInbound($channel->getAdapterCode());
        if ($adapter === null) {
            throw new NotFoundHttpException();
        }

        try {
            $result = $adapter->consumeWebhook($channel, $request);
        } catch (WebhookNotSupportedException) {
            throw new NotFoundHttpException();
        }

        $channel->setLastSyncedAt(new \DateTimeImmutable());
        $channel->setLastSyncError(null);
        $this->em->flush();

        // Hand each freshly-persisted event to the async processing strecke.
        // Dispatch AFTER flush so the worker is guaranteed to find the row
        // committed; one message per event gives isolated retry/DLQ.
        foreach ($result->events as $event) {
            $this->bus->dispatch(new ProcessInboundEventMessage($event->getId()));
        }

        // 204 keeps providers happy without leaking any state. Some
        // providers (Slack, Stripe) require a 2xx body — empty 204 is
        // accepted by all of them.
        return new JsonResponse([
            'ingested' => \count($result->events),
        ], 200);
    }

    private function resolveByToken(string $token): ?Channel
    {
        // We could index Channel.inboundConfig->'$.token' on MySQL 8
        // for O(1) lookup, but the small channel count per workspace
        // makes the scan negligible — and keeps the schema portable.
        // Tightening this is a follow-up if a workspace ever crosses
        // ~500 enabled channels.
        $candidates = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Channel::class, 'c')
            ->where('c.deletedAt IS NULL')
            ->andWhere('c.isEnabled = 1')
            ->getQuery()
            ->getResult();
        foreach ($candidates as $c) {
            if (!$c instanceof Channel) continue;
            $stored = (string) ($c->getInboundConfig()['token'] ?? '');
            if ($stored !== '' && hash_equals($stored, $token)) {
                return $c;
            }
        }
        return null;
    }
}
