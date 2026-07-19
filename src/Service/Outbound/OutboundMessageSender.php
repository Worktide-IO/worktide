<?php

declare(strict_types=1);

namespace App\Service\Outbound;

use App\Channels\AdapterRegistry;
use App\Entity\Enum\OutboundMessageStatus;
use App\Entity\OutboundMessage;
use App\Egress\EgressBlockedException;
use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use Psr\Log\LoggerInterface;

/**
 * Sends a single {@see OutboundMessage} through its channel's outbound adapter.
 *
 * This is the focused core of the Phase-C.3 outbound delivery layer: it delivers
 * ONE explicitly-dispatched message rather than polling the whole Queued backlog.
 * That distinction is deliberate — a poll-all worker would start delivering the
 * existing human-in-the-loop drafts (AI outreach, absence-notify) the moment it
 * shipped. Callers that want a message sent dispatch {@see \App\Message\SendOutboundMessage}.
 *
 * Every send is gated by the {@see EgressGuard} ({@see EgressModule::EmailOutbound}).
 * A withheld egress is NOT a failure — the message stays Queued so it goes out
 * once the operator approves the module (the established call-site contract).
 *
 * The caller (the message handler) owns the flush; this service only mutates the
 * managed entity so it stays composable and unit-testable.
 */
final class OutboundMessageSender
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly EgressGuard $egress,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return bool true if the provider accepted the message, false if it was
     *              withheld (egress) or permanently failed. Transient failures
     *              throw so the messenger retry strategy re-runs the handler.
     */
    public function send(OutboundMessage $message): bool
    {
        // Idempotency: only Queued messages are eligible. A concurrent worker
        // or a retry that already delivered leaves Sent/Sending untouched.
        if ($message->getStatus() !== OutboundMessageStatus::Queued) {
            return $message->getStatus() === OutboundMessageStatus::Sent;
        }

        $channel = $message->getChannel();
        $adapter = $this->registry->tryOutbound($channel->getAdapterCode());
        if ($adapter === null) {
            $message->setStatus(OutboundMessageStatus::Failed)
                ->setStatusReason(sprintf('No outbound adapter for channel code "%s".', $channel->getAdapterCode()));

            return false;
        }

        // Egress gate. Withheld → leave Queued so it ships after approval.
        try {
            $this->egress->assertAllowed(EgressModule::EmailOutbound, $channel);
        } catch (EgressBlockedException $e) {
            $this->logger->info('Outbound message withheld by egress gate.', [
                'outboundMessageId' => $message->getId()?->toRfc4122(),
                'module' => $e->module->value,
                'channelId' => $channel->getId()?->toRfc4122(),
            ]);

            return false;
        }

        $message->setStatus(OutboundMessageStatus::Sending)->incrementAttempts();
        $result = $adapter->send($channel, $message);

        if ($result->sent) {
            $message->setStatus(OutboundMessageStatus::Sent)
                ->setSentAt(new \DateTimeImmutable())
                ->setExternalId($result->externalId)
                ->setStatusReason(null);

            $this->logger->info('Outbound message sent.', [
                'outboundMessageId' => $message->getId()?->toRfc4122(),
                'externalId' => $result->externalId,
            ]);

            return true;
        }

        if ($result->retry) {
            // Transient — hand back to Queued and let messenger retry the handler.
            $message->setStatus(OutboundMessageStatus::Queued)->setStatusReason($result->reason);

            throw new \RuntimeException(sprintf(
                'Transient outbound failure (attempt %d): %s',
                $message->getAttemptCount(),
                $result->reason ?? 'unknown',
            ));
        }

        $message->setStatus(OutboundMessageStatus::Failed)->setStatusReason($result->reason);
        $this->logger->warning('Outbound message failed.', [
            'outboundMessageId' => $message->getId()?->toRfc4122(),
            'reason' => $result->reason,
        ]);

        return false;
    }
}
