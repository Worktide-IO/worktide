<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Channels\ChannelSyncRunner;
use App\Entity\Channel;
use App\Message\RunChannelSyncMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Runs a manually-triggered channel sync off the request thread via
 * {@see ChannelSyncRunner}. Re-loads the Channel by id so a redelivery acts on
 * current state; a purged channel drops the job (no infinite retry).
 */
#[AsMessageHandler]
final class RunChannelSyncHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChannelSyncRunner $runner,
    ) {}

    public function __invoke(RunChannelSyncMessage $message): void
    {
        $channel = $this->em->find(Channel::class, $message->getChannelId());
        if ($channel === null) {
            throw new UnrecoverableMessageHandlingException(sprintf(
                'Channel %s no longer exists; dropping sync job.',
                $message->getChannelId()->toRfc4122(),
            ));
        }

        $this->runner->run($channel, $message->isLive());
    }
}
