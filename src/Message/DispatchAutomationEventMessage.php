<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction to notify the external automation engine (self-hosted n8n)
 * that an {@see \App\Entity\InboundEvent} has been processed.
 *
 * Dispatched (live only) at the tail of {@see \App\Service\Inbound\InboundEventProcessor}
 * so a slow or absent n8n never blocks the inbound-processing worker. Like
 * {@see ProcessInboundEventMessage} the event ROW is the source of truth — we
 * carry only its id and re-load on handle, so a redelivery reflects current
 * state. The handler no-ops when the feature is unconfigured or the `automation`
 * egress module isn't approved.
 */
final class DispatchAutomationEventMessage
{
    public function __construct(
        private readonly Uuid $inboundEventId,
    ) {}

    public function getInboundEventId(): Uuid
    {
        return $this->inboundEventId;
    }
}
