<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\Enum\InboundEventState;
use App\Entity\InboundEvent;
use Psr\Log\LoggerInterface;

/**
 * Turns a Pending {@see InboundEvent} into work and marks it settled.
 *
 * This is the single processing strecke both ingest paths funnel into
 * (webhook push via {@see \App\Controller\Api\WebhookIngestController} and
 * cron pull via {@see \App\Command\ChannelPullCommand}). Threading
 * (event → Conversation) already happened in the adapter; this stage acts on
 * the threaded result.
 *
 * Skeleton: today it just settles the event to Processed. The real pipeline
 * slots into the numbered seams below, each as a separate, independently
 * testable collaborator added to the constructor when it lands:
 *
 *   1. Import-filter   — only events addressed to a workspace person (direct
 *                        assignee or watcher/Mitleser). Irrelevant → Dismissed.
 *                        Shared with the adapter-side pre-filter so backfill and
 *                        webhook filter identically via {@see InboundImportFilter}.
 *                        (ROADMAP: Phase C Schicht 5; the discovered-import
 *                        consumer is C.7.6, still open.)
 *   2. Sender resolve  — from-address → Contact → Customer / project context.
 *   3. Rule engine     — the workspace's inbound automations ("inbound.received"
 *                        trigger): create Task, assign Conversation, tag, etc.
 *   4. AI classify     — Phase D: dispatch a further Messenger step behind
 *                        LlmProviderInterface (same retry/DLQ guarantees).
 *
 * The handler flushes; this service must not flush so it stays composable.
 */
final class InboundEventProcessor
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ContactResolver $contactResolver,
        // Seams (inject when implemented):
        // private readonly InboundImportFilter $importFilter,
        // private readonly InboundRuleEngine $rules,
    ) {}

    public function process(InboundEvent $event): void
    {
        // 1. Import-filter — defense-in-depth guard for sources that can't
        //    pre-filter at the adapter (e.g. a shared mailbox).
        // if (!$this->importFilter->isRelevant($event)) {
        //     $event->setState(InboundEventState::Dismissed);
        //     return;
        // }

        // 2. Sender resolution — match the from-email onto a known Contact and
        //    propagate its Customer onto the conversation (auto-resolve).
        $this->contactResolver->resolveForEvent($event);

        // 3. Apply inbound rules (may create a Task / assign the Conversation) …
        // 4. Optional AI classification (Phase D) …

        $event->setState(InboundEventState::Processed);

        $this->logger->info('Inbound event processed.', [
            'inboundEventId' => $event->getId()?->toRfc4122(),
            'channel' => $event->getChannel()->getAdapterCode(),
        ]);
    }
}
