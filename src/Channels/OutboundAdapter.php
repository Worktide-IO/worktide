<?php

declare(strict_types=1);

namespace App\Channels;

use App\Entity\Channel;
use App\Entity\OutboundMessage;

/**
 * Implemented by every concrete channel that *sends* messages out
 * — SMTP for mail, Graph for MS 365, Gmail API, slack post-message,
 * twilio for SMS, etc.
 *
 * Implementations are stateless transport layers: they take a
 * fully-prepared OutboundMessage row and hand it to their provider.
 * Status updates (Sent / Failed) are written back by the caller
 * (the OutboundQueue worker — Phase C.3), not by the adapter
 * itself — adapters only return an {@see OutboundResult} describing
 * what happened.
 *
 * Tagged service discovery: same registry + tag attribute as
 * {@see InboundAdapter}.
 */
interface OutboundAdapter
{
    public function getCode(): string;

    public function getLabel(): string;

    /**
     * Hand the message to the provider. Implementations should:
     *   - Use Channel.outboundConfig + Channel.authConfig for endpoint
     *     credentials.
     *   - Surface bounces / permanent errors via OutboundResult.failed +
     *     OutboundResult.reason rather than throwing — the worker
     *     decides whether to retry based on that.
     *   - NEVER mutate the OutboundMessage entity directly.
     */
    public function send(Channel $channel, OutboundMessage $message): OutboundResult;
}
