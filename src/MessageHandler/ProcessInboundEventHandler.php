<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Enum\InboundEventState;
use App\Entity\InboundEvent;
use App\Message\ProcessInboundEventMessage;
use App\Service\Inbound\InboundEventProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Runs the inbound-processing pipeline for one InboundEvent, off the request
 * thread. Mirrors {@see SendWebhookHandler}: re-load by id, drop unrecoverably
 * when the row is gone, let recoverable failures bubble so the transport's
 * retry strategy (and ultimately the `failed` transport) handles them.
 *
 * Idempotency: only events still in {@see InboundEventState::Pending} are
 * processed. A redelivery of an already-settled event (Processed/Dismissed) is
 * a no-op — this is what makes at-least-once delivery safe and lets a job be
 * replayed from the failed transport without creating duplicate work.
 */
#[AsMessageHandler]
final class ProcessInboundEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InboundEventProcessor $processor,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessInboundEventMessage $message): void
    {
        $event = $this->em->find(InboundEvent::class, $message->getInboundEventId());

        // Row purged (channel deleted, retention sweep) — don't retry forever.
        if ($event === null) {
            throw new UnrecoverableMessageHandlingException(sprintf(
                'InboundEvent %s no longer exists; dropping.',
                $message->getInboundEventId()->toRfc4122(),
            ));
        }

        if ($event->getState() !== InboundEventState::Pending) {
            $this->logger->debug('InboundEvent already settled; skipping.', [
                'inboundEventId' => $event->getId()?->toRfc4122(),
                'state' => $event->getState()->value,
            ]);

            return;
        }

        // All business logic lives in the service (matches the project's
        // service pattern). It sets the terminal state on the event; a thrown
        // (recoverable) exception leaves the event Pending so the retry can
        // re-run it cleanly.
        $this->processor->process($event);

        $this->em->flush();
    }
}
