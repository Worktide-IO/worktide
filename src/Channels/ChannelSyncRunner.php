<?php

declare(strict_types=1);

namespace App\Channels;

use App\Entity\Channel;
use App\Message\ProcessInboundEventMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs a single channel's inbound sync on demand — the shared core behind both
 * the scheduled {@see \App\Command\ChannelPullCommand} and the operator-triggered
 * `POST /v1/channels/{id}/sync` ({@see \App\Controller\Api\ChannelSyncController}).
 *
 * Two adapter shapes are dispatched here:
 *
 *   - **Entity-sync adapters** ({@see SyncableAdapter} with entityTypes set —
 *     Redmine/Jira): pull normalised snapshots and hand each to
 *     {@see EntityApplier}, which updates already-mapped entities and parks the
 *     rest as {@see \App\Entity\DiscoveredExternalRecord} for review.
 *   - **Inbound adapters** ({@see InboundAdapter} — mail feeds, and the
 *     master-data importers Lexoffice/Awork whose pull() match-or-creates
 *     customers/projects directly): pull, persist the cursor, and fan each new
 *     event out to {@see ProcessInboundEventMessage}.
 *
 * Updates lastSyncedAt / lastSyncError on the channel so the SPA's source list
 * reflects the outcome. Re-throws on failure so Messenger can retry.
 */
final class ChannelSyncRunner
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly EntityApplier $applier,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(Channel $channel, bool $live = true): void
    {
        $code = $channel->getAdapterCode();

        try {
            $sync = $this->registry->trySync($code);
            if ($sync !== null && $channel->isEntitySyncEnabled()) {
                $this->runEntitySync($channel, $sync);

                return;
            }

            $inbound = $this->registry->tryInbound($code);
            if ($inbound === null) {
                throw new \RuntimeException(sprintf('Adapter "%s" supports neither entity-sync nor inbound pull.', $code));
            }
            $this->runInboundPull($channel, $inbound, $live);
        } catch (PullNotSupportedException) {
            // Push-only adapter (webhook-driven) — a manual "sync now" is a no-op,
            // not an error. Touch lastSyncedAt so the UI shows it was attempted.
            $channel->setLastSyncedAt(new \DateTimeImmutable())->setLastSyncError(null);
            $this->em->flush();
        } catch (\Throwable $e) {
            $channel->setLastSyncError($this->formatError($e));
            $this->em->flush();
            $this->logger->error('Channel sync failed', ['channel' => (string) $channel->getId(), 'adapter' => $code, 'error' => $e->getMessage()]);

            throw $e;
        } finally {
            $this->clearSyncMarker($channel);
        }
    }

    /**
     * Clear the in-progress marker so the SPA's "läuft…" state ends. Guarded: a
     * DB error may have closed the EM — then the stale window expires the marker.
     */
    private function clearSyncMarker(Channel $channel): void
    {
        if (!$this->em->isOpen()) {
            return;
        }
        try {
            $channel->setSyncStartedAt(null);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Could not clear channel sync marker', ['error' => $e->getMessage()]);
        }
    }

    private function runEntitySync(Channel $channel, SyncableAdapter $sync): void
    {
        foreach ($sync->pullEntities($channel, null) as $snapshot) {
            $this->applier->apply($channel, $snapshot);
        }
        $channel->setLastSyncedAt(new \DateTimeImmutable())->setLastSyncError(null);
        $this->em->flush();
    }

    private function runInboundPull(Channel $channel, InboundAdapter $inbound, bool $live): void
    {
        $result = $inbound->pull($channel);

        if ($result->cursor !== null) {
            $config = $channel->getInboundConfig() ?? [];
            $config['cursor'] = $result->cursor;
            $channel->setInboundConfig($config);
        }
        $channel->setLastSyncedAt(new \DateTimeImmutable())->setLastSyncError(null);
        $this->em->flush();

        foreach ($result->events as $event) {
            $this->bus->dispatch(new ProcessInboundEventMessage($event->getId(), $live));
        }
    }

    private function formatError(\Throwable $e): string
    {
        return mb_substr(sprintf('%s @%s:%d', $e->getMessage(), basename($e->getFile()), $e->getLine()), 0, 500);
    }
}
