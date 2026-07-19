<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendOutboundMessage;
use App\Repository\OutboundMessageRepository;
use App\Service\Outbound\OutboundMessageSender;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Delivers a single queued {@see \App\Entity\OutboundMessage}. Re-loads the row
 * (the message carries only the id) so a deleted or already-sent message is a
 * clean no-op. Flushes the status write-back the sender staged.
 */
#[AsMessageHandler]
final class SendOutboundMessageHandler
{
    public function __construct(
        private readonly OutboundMessageRepository $repository,
        private readonly OutboundMessageSender $sender,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SendOutboundMessage $message): void
    {
        $outbound = $this->repository->find($message->getOutboundMessageId());
        if ($outbound === null) {
            $this->logger->info('SendOutboundMessage: message gone, skipping.', [
                'outboundMessageId' => $message->getOutboundMessageId()->toRfc4122(),
            ]);

            return;
        }

        try {
            $this->sender->send($outbound);
        } finally {
            // Persist whatever state the send attempt reached (Sent/Failed/Queued),
            // even when a transient failure re-throws for a messenger retry.
            $this->em->flush();
        }
    }
}
