<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction to run the processing pipeline for one freshly-ingested
 * {@see \App\Entity\InboundEvent}.
 *
 * Ingest (adapter pull/webhook) only persists the raw event + threads it onto a
 * Conversation; the actual work — import-filtering, sender resolution, applying
 * the workspace's inbound rules, materializing tasks — happens here, off the
 * request thread, with Messenger's retry + failed-transport guarantees.
 *
 * Unlike {@see SendWebhookMessage} (which carries the exact signed body to
 * replay), the InboundEvent ROW is the source of truth, so we carry only its id
 * and re-load on handle. A redelivery therefore acts on the current row state,
 * and the handler's state guard makes at-least-once delivery safe.
 */
final class ProcessInboundEventMessage
{
    public function __construct(
        private readonly Uuid $inboundEventId,
    ) {}

    public function getInboundEventId(): Uuid
    {
        return $this->inboundEventId;
    }
}
