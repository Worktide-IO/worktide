<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Zabbix;

use App\Channels\ConversationThreader;
use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Enum\ConversationStatus;
use App\Entity\InboundEvent;
use App\Repository\ConversationRepository;
use App\Repository\CustomerSystemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Conversation-threading strategy for the Zabbix channel.
 *
 * One Conversation per host+trigger (`threadKey = "zabbix:<hostid>:<triggerId>"`)
 * so a flapping problem re-uses the same thread instead of spawning hundreds of
 * conversations. A recovery event ({@see InboundEvent} with
 * `sourceMetadata.resolved = true`) closes the thread; a fresh problem on a
 * closed thread re-opens it.
 *
 * Host → Customer auto-link: if a {@see \App\Entity\CustomerSystem} carries the
 * external reference `zabbix:<hostid>`, the thread adopts that system's Customer.
 * Until an operator assigns the host to a customer (via
 * {@see \App\Controller\Api\ConversationLinkSystemController}) the conversation
 * simply stays customer-less.
 */
final class ZabbixThreader implements ConversationThreader
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly CustomerSystemRepository $systems,
        private readonly EntityManagerInterface $em,
    ) {}

    public function attach(Channel $channel, InboundEvent $event): Conversation
    {
        $meta = $event->getSourceMetadata();
        $hostId = (string) ($meta['hostid'] ?? '');
        $triggerId = (string) ($meta['triggerId'] ?? '');
        $resolved = ($meta['resolved'] ?? false) === true;

        $threadKey = sprintf('zabbix:%s:%s', $hostId, $triggerId);

        $conversation = $this->conversations->findByThreadKey($channel, $threadKey);
        if ($conversation === null) {
            $conversation = (new Conversation())
                ->setWorkspace($channel->getWorkspace())
                ->setChannel($channel)
                ->setThreadKey($threadKey)
                ->setSubject($this->subjectFor($event, $meta))
                ->setSenderRaw($event->getSenderRaw())
                ->setStatus($resolved ? ConversationStatus::Closed : ConversationStatus::Open)
                ->setLastEventAt($event->getReceivedAt());
            $this->autoLinkCustomer($channel, $conversation, $hostId);
            $this->em->persist($conversation);
            $event->setConversation($conversation);

            return $conversation;
        }

        // Existing thread: recovery closes it, a new problem re-opens it.
        $conversation->setStatus($resolved ? ConversationStatus::Closed : ConversationStatus::Open);
        $conversation->setLastEventAt($event->getReceivedAt());
        if ($conversation->getSenderRaw() === null && $event->getSenderRaw() !== null) {
            $conversation->setSenderRaw($event->getSenderRaw());
        }
        // A host may have been assigned to a customer after this thread started —
        // adopt it now if the thread is still customer-less.
        $this->autoLinkCustomer($channel, $conversation, $hostId);
        $event->setConversation($conversation);

        return $conversation;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function subjectFor(InboundEvent $event, array $meta): string
    {
        $subject = (string) $event->getSubject();
        $hostName = (string) ($meta['hostVisibleName'] ?? '');
        if ($hostName !== '') {
            $subject = $hostName . ' — ' . $subject;
        }
        if (trim($subject) === '') {
            $subject = 'Zabbix-Problem';
        }

        return mb_substr($subject, 0, 250);
    }

    private function autoLinkCustomer(Channel $channel, Conversation $conversation, string $hostId): void
    {
        if ($conversation->getCustomer() !== null || $hostId === '') {
            return;
        }
        $system = $this->systems->findByExternalRef(
            $channel->getWorkspace(),
            ZabbixAdapter::CODE,
            $hostId,
        );
        if ($system !== null) {
            $conversation->setCustomer($system->getCustomer());
        }
    }
}
