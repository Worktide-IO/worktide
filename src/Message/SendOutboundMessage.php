<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction to deliver one {@see \App\Entity\OutboundMessage} through
 * its channel adapter (handled by {@see \App\MessageHandler\SendOutboundMessageHandler}
 * via {@see \App\Service\Outbound\OutboundMessageSender}).
 *
 * Routed to the fast `async` transport — a receipt acknowledgement or a reply is
 * a quick SMTP hand-off, not a slow LLM job. Carries only the id; the handler
 * re-loads so a stale/already-sent row is a no-op.
 */
final class SendOutboundMessage
{
    public function __construct(
        private readonly Uuid $outboundMessageId,
    ) {}

    public function getOutboundMessageId(): Uuid
    {
        return $this->outboundMessageId;
    }
}
