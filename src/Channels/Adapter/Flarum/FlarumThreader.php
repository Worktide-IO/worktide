<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Flarum;

use App\Channels\ConversationThreader;
use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Enum\ConversationStatus;
use App\Entity\InboundEvent;
use App\Repository\ConversationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Conversation-threading strategy for the Flarum channel.
 *
 * One Conversation per discussion (`threadKey = "flarum:<discussionId>"`).
 * All events for the same discussion thread under the same Conversation.
 * Subsequent events on an existing thread update lastEventAt but leave the
 * thread open (a monitoring channel never auto-closes just because a newer
 * match appeared).
 */
final class FlarumThreader implements ConversationThreader
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly EntityManagerInterface $em,
    ) {}

    public function attach(Channel $channel, InboundEvent $event): Conversation
    {
        $meta = $event->getSourceMetadata();
        $discussionId = (int) ($meta['discussionId'] ?? 0);

        $threadKey = sprintf('flarum:%d', $discussionId);

        $conversation = $this->conversations->findByThreadKey($channel, $threadKey);
        if ($conversation === null) {
            $conversation = (new Conversation())
                ->setWorkspace($channel->getWorkspace())
                ->setChannel($channel)
                ->setThreadKey($threadKey)
                ->setSubject(mb_substr((string) $event->getSubject(), 0, 250))
                ->setSenderRaw($event->getSenderRaw())
                ->setStatus(ConversationStatus::Open)
                ->setLastEventAt($event->getReceivedAt());
            $this->em->persist($conversation);
            $event->setConversation($conversation);

            return $conversation;
        }

        if ($event->getReceivedAt() >= $conversation->getLastEventAt()) {
            $conversation->setLastEventAt($event->getReceivedAt());
        }
        if ($conversation->getSenderRaw() === null && $event->getSenderRaw() !== null) {
            $conversation->setSenderRaw($event->getSenderRaw());
        }
        $event->setConversation($conversation);

        return $conversation;
    }
}
