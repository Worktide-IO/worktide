<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\Channel;
use App\Entity\Enum\ChannelCapability;
use App\Entity\Enum\OutboundMessageKind;
use App\Entity\Enum\OutboundMessageStatus;
use App\Entity\InboundEvent;
use App\Entity\OutboundMessage;
use App\Message\SendOutboundMessage;
use App\Repository\OutboundMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Per-mailbox auto-responder (receipt acknowledgement).
 *
 * Fires for BOTH personal and shared mailboxes — the message is configured on
 * the {@see Channel} (personal = the owner's, shared = the team's), so this
 * service just reads whatever the mailbox has enabled. Contrast with ticket
 * auto-suggestion, which is shared-only for cost control.
 *
 * Loop safety is layered:
 *   1. {@see MailRelevanceClassifier} drops bulk / auto-submitted / no-reply
 *      mail before we ever reply — the primary mail-loop guard.
 *   2. A per-sender throttle ({@see OutboundMessageRepository::hasRecentAutoReply})
 *      de-bounces a back-and-forth thread to one receipt per window.
 *   3. The sent mail carries Auto-Submitted / X-Auto-Response-Suppress so a
 *      remote responder won't answer ours (added by the email adapters).
 *
 * The created {@see OutboundMessage} is Queued and handed to the async sender
 * ({@see SendOutboundMessage}) — dispatched after the current bus so the row is
 * committed before the consumer loads it. The egress gate lives in the sender;
 * a withheld send simply leaves the receipt Queued.
 *
 * Does not flush — the caller ({@see InboundEventProcessor}) owns the flush.
 */
final class AutoReplyResponder
{
    public function __construct(
        private readonly MailRelevanceClassifier $relevance,
        private readonly OutboundMessageRepository $outboundRepository,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function maybeReply(InboundEvent $event): void
    {
        $channel = $event->getChannel();

        if (!$channel->hasUsableAutoReply() || !$channel->supports(ChannelCapability::Outbound)) {
            return;
        }

        // Loop guard #1: never auto-reply to bulk / automated / no-reply mail.
        if (!$this->relevance->isActionable($event)) {
            return;
        }

        $recipient = $this->replyAddress($event);
        if ($recipient === null) {
            return;
        }

        // Never reply to one of the workspace's own mailbox addresses (self-loop).
        if ($this->isOwnAddress($channel, $recipient)) {
            return;
        }

        // Loop guard #2: per-sender throttle window.
        $throttleHours = $channel->getAutoReplyThrottleHours();
        if ($throttleHours > 0) {
            $since = new \DateTimeImmutable(sprintf('-%d hours', $throttleHours));
            if ($this->outboundRepository->hasRecentAutoReply($channel, $recipient, $since)) {
                $this->logger->debug('Auto-reply throttled for recipient.', [
                    'channelId' => $channel->getId()?->toRfc4122(),
                    'recipient' => $recipient,
                ]);

                return;
            }
        }

        $message = (new OutboundMessage())
            ->setChannel($channel)
            ->setRecipientRaw($recipient)
            ->setRecipientContact($event->getSenderContact())
            ->setSubject($this->replySubject($channel, $event))
            ->setBody($channel->getAutoReplyBodyText() ?? $this->htmlToText($channel->getAutoReplyBodyHtml() ?? ''))
            ->setBodyHtml($channel->getAutoReplyBodyHtml())
            ->setConversation($event->getConversation())
            ->setInReplyToInboundEvent($event)
            ->setKind(OutboundMessageKind::AutoReply)
            ->setStatus(OutboundMessageStatus::Queued);
        $message->setWorkspace($channel->getWorkspace());

        $this->em->persist($message);

        // Send after the current bus completes so the row is committed before
        // the async consumer re-loads it (avoids the send-before-commit race).
        $this->bus->dispatch(
            new SendOutboundMessage($message->getId()),
            [new DispatchAfterCurrentBusStamp()],
        );

        $this->logger->info('Auto-reply queued.', [
            'channelId' => $channel->getId()?->toRfc4122(),
            'recipient' => $recipient,
            'outboundMessageId' => $message->getId()?->toRfc4122(),
        ]);
    }

    private function replySubject(Channel $channel, InboundEvent $event): string
    {
        $configured = $channel->getAutoReplySubject();
        if ($configured !== null && trim($configured) !== '') {
            return $configured;
        }
        $subject = trim((string) ($event->getSubject() ?? ''));
        if ($subject === '') {
            return 'Ihre Nachricht ist eingegangen';
        }

        return preg_match('/^re:/i', $subject) === 1 ? $subject : 'Re: ' . $subject;
    }

    /**
     * Bare, validated address to reply to — mirrors ContactResolver's parser
     * so a `Name <email>` from-header resolves the same way.
     */
    private function replyAddress(InboundEvent $event): ?string
    {
        $raw = trim((string) $event->getSenderRaw());
        if ($raw === '') {
            return null;
        }
        if (preg_match('/<([^>]+)>/', $raw, $m) === 1) {
            $raw = trim($m[1]);
        }

        return filter_var($raw, \FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($raw) : null;
    }

    private function isOwnAddress(Channel $channel, string $recipient): bool
    {
        $own = array_filter([
            $channel->getAddress(),
            $channel->getOutboundConfig()['from'] ?? null,
        ]);
        foreach ($own as $addr) {
            if (is_string($addr) && mb_strtolower(trim($addr)) === $recipient) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minimal HTML→text fallback for the plaintext part when only an HTML body
     * was configured. Not a full renderer — strips tags and decodes entities so
     * the multipart/alternative always has a readable text alternative.
     */
    private function htmlToText(string $html): string
    {
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\s*\/\s*p\s*>/i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);

        return trim(html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }
}
