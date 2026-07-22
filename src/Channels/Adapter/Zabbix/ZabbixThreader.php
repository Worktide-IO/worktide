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
 * ## Order-independence
 *
 * Events are processed asynchronously (worktide:channel:pull → messenger), so
 * their processing order is NOT guaranteed: a raise whose threading fails
 * transiently is retried with backoff and can land *after* the recovery that
 * superseded it. The state a thread carries (status + subject) must therefore
 * be driven by the newest event's timestamp, not by whichever event happens to
 * be processed last — otherwise a stale raise re-opens a thread the recovery
 * already closed, and the thread keeps a "Behoben:" subject. So status/subject
 * updates on an existing thread are only applied when `event.receivedAt` is at
 * or after the thread's `lastEventAt`, and the display subject is always the
 * bare problem name (never the recovery's "Behoben:" prefix).
 *
 * Host → Customer auto-link: if a {@see \App\Entity\CustomerSystem} carries the
 * external reference `zabbix:<hostid>`, the thread adopts that system's Customer.
 * Until an operator assigns the host to a customer (via
 * {@see \App\Controller\Api\ConversationLinkSystemController}) the conversation
 * simply stays customer-less.
 */
final class ZabbixThreader implements ConversationThreader
{
    /** Subject prefix a recovery event carries; stripped from the display subject. */
    private const RECOVERY_SUBJECT_PREFIX = 'Behoben: ';

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

        // Existing thread: recovery closes it, a new problem re-opens it — but
        // only when this event is the newest one seen (by Zabbix clock / pull
        // time). An out-of-order older event (e.g. a raise retried after its
        // recovery) must not re-open a thread the newer recovery already closed,
        // nor drag the subject backwards.
        if ($event->getReceivedAt() >= $conversation->getLastEventAt()) {
            $conversation->setStatus($resolved ? ConversationStatus::Closed : ConversationStatus::Open);
            $conversation->setLastEventAt($event->getReceivedAt());
            // Keep the display subject on the current problem name (recoveries
            // reuse the raise's subject, so this stays stable across a flap).
            $conversation->setSubject($this->subjectFor($event, $meta));
        }
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
        // A recovery event's subject is the raise's subject with a "Behoben:"
        // prefix — strip it so the thread never displays as "Behoben: …" (which
        // would stick even after the problem re-opens).
        if (str_starts_with($subject, self::RECOVERY_SUBJECT_PREFIX)) {
            $subject = substr($subject, \strlen(self::RECOVERY_SUBJECT_PREFIX));
        }
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
