<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\UserChatWebhook;
use App\Message\NotifyChatMessage;
use App\Notification\Chat\ChatWebhookSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Delivers one notification to one user's chat webhook (Slack/Mattermost/Teams),
 * off the flush. Best-effort — the in-app + email channels are the source of
 * truth, so a chat failure just logs (via {@see ChatWebhookSender}).
 */
#[AsMessageHandler]
final class SendChatNotificationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChatWebhookSender $sender,
    ) {}

    public function __invoke(NotifyChatMessage $message): void
    {
        $webhook = $this->em->find(UserChatWebhook::class, $message->getWebhookId());
        if (!$webhook instanceof UserChatWebhook) {
            throw new UnrecoverableMessageHandlingException('Chat webhook no longer exists.');
        }
        if (!$webhook->isEnabled()) {
            return; // disabled mid-flight — skip cleanly
        }

        $this->sender->send($webhook, $message->getTitle(), $message->getBody(), $message->getActionUrl());
    }
}
