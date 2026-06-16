<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Email;

use App\Channels\ConversationThreader;
use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Enum\ConversationStatus;
use App\Entity\InboundEvent;
use App\Repository\ConversationRepository;
use App\Repository\InboundEventRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Conversation-threading strategy for mail channels.
 *
 * The threadKey for a mail Conversation is the **root Message-ID** of
 * the thread. Every event in the thread refers to it via its
 * References-chain.
 *
 * Resolution order when a new mail arrives:
 *
 *   1. If the event's `In-Reply-To` or first `References` entry
 *      matches an existing InboundEvent.externalId in the same
 *      channel, reuse that event's Conversation.
 *   2. Otherwise the event itself is the root — create a Conversation
 *      whose threadKey is the event's own externalId (Message-ID).
 *
 * Edge cases handled:
 *
 *   - References chain present but no event we know of → treat the
 *     first References entry as the threadKey anyway; future replies
 *     in the thread will land on the same conversation even if we
 *     never received the original (handy when we get added to a
 *     long-running thread mid-flight).
 *   - Subject-based threading is NOT implemented. It's too noisy
 *     ("Re: meeting" matches across unrelated threads). Strict
 *     header-only.
 *
 * The threader expects the adapter to have populated
 * `sourceMetadata.headers` with at least `Message-ID`, `In-Reply-To`,
 * `References`, and `Subject`. The adapter is the only place those
 * raw headers live; the threader doesn't re-parse RFC-5322.
 */
final class MailThreader implements ConversationThreader
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly InboundEventRepository $events,
        private readonly EntityManagerInterface $em,
    ) {}

    public function attach(Channel $channel, InboundEvent $event): Conversation
    {
        $headers = $event->getSourceMetadata()['headers'] ?? [];

        $threadKey = $this->resolveThreadKey($channel, $headers, $event);

        // Look up an existing Conversation under that key first.
        $existing = $this->conversations->findByThreadKey($channel, $threadKey);
        if ($existing !== null) {
            $event->setConversation($existing);
            $existing->setLastEventAt($event->getReceivedAt());
            if ($event->getSenderRaw() !== null) {
                $existing->setSenderRaw($existing->getSenderRaw() ?? $event->getSenderRaw());
            }
            return $existing;
        }

        // First event in the thread — create a Conversation row.
        $conversation = (new Conversation())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setThreadKey($threadKey)
            ->setSubject($this->normaliseSubject((string) $event->getSubject()))
            ->setSenderRaw($event->getSenderRaw())
            ->setStatus(ConversationStatus::Open)
            ->setLastEventAt($event->getReceivedAt());

        // Carry the resolved Contact, if the adapter already matched one.
        if ($event->getSenderContact() !== null) {
            $conversation->setCustomer($event->getSenderContact()->getCustomer());
        }

        $this->em->persist($conversation);
        $event->setConversation($conversation);
        return $conversation;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function resolveThreadKey(Channel $channel, array $headers, InboundEvent $event): string
    {
        // 1. In-Reply-To → find that event's existing Conversation.
        $inReplyTo = $this->firstMessageId($headers['In-Reply-To'] ?? null);
        if ($inReplyTo !== null) {
            $parent = $this->events->findByExternalId($channel, $inReplyTo);
            if ($parent?->getConversation() !== null) {
                return $parent->getConversation()->getThreadKey();
            }
            // Otherwise treat the In-Reply-To value itself as the root —
            // future events with the same chain will collapse here.
            return $inReplyTo;
        }

        // 2. References-chain — first entry is conventionally the root.
        $references = $this->parseReferences($headers['References'] ?? null);
        if ($references !== []) {
            $root = $references[0];
            $parent = $this->events->findByExternalId($channel, $root);
            if ($parent?->getConversation() !== null) {
                return $parent->getConversation()->getThreadKey();
            }
            return $root;
        }

        // 3. No threading info at all — the event is its own root.
        return $event->getExternalId();
    }

    /**
     * Strip Re:/Fwd:/Aw: prefixes from a subject for storage on the
     * Conversation row. The original subject is kept on each
     * InboundEvent verbatim.
     */
    private function normaliseSubject(string $subject): string
    {
        $clean = preg_replace('/^\s*(re|aw|fwd|wg|fw)\s*(\[\d+\])?\s*:\s*/i', '', $subject) ?? $subject;
        $clean = trim($clean);
        if ($clean === '') {
            $clean = '(no subject)';
        }
        return mb_substr($clean, 0, 250);
    }

    /**
     * "<id1@host>" → "id1@host". Returns null if no usable id found.
     */
    private function firstMessageId(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        if (preg_match('/<([^>]+)>/', $raw, $m) === 1) {
            return $m[1];
        }
        $trim = trim($raw);
        return $trim === '' ? null : $trim;
    }

    /**
     * References-header is space-separated `<id1> <id2> ...`.
     *
     * @return list<string>
     */
    private function parseReferences(mixed $raw): array
    {
        if (!is_string($raw)) {
            return [];
        }
        if (preg_match_all('/<([^>]+)>/', $raw, $m) === false) {
            return [];
        }
        return array_values(array_filter($m[1] ?? []));
    }
}
