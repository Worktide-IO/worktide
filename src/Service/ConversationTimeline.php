<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\Enum\OutboundMessageKind;
use App\Entity\User;
use App\Repository\ConversationNoteRepository;
use App\Repository\InboundEventRepository;
use App\Repository\OutboundMessageRepository;

/**
 * Builds the unified thread view of a {@see Conversation} (Phase C Schicht 2)
 * by merging its three message sources chronologically:
 *
 *   InboundEvent    → type `customer`  (incoming customer message)
 *   OutboundMessage → type `message`   (agent reply) / `forward` (kind=Forward)
 *   ConversationNote→ type `note`      (private internal note)
 *
 * This is the read-side equivalent of the roadmap's single Thread entity —
 * without rewriting the load-bearing ingest/outbound entities. Pure assembly;
 * authorization is the controller's job.
 */
final class ConversationTimeline
{
    public function __construct(
        private readonly InboundEventRepository $inbound,
        private readonly OutboundMessageRepository $outbound,
        private readonly ConversationNoteRepository $notes,
    ) {}

    /**
     * @return list<array<string, mixed>> chronologically ascending
     */
    public function build(Conversation $conversation): array
    {
        /** @var list<array{at: \DateTimeImmutable, item: array<string, mixed>}> $rows */
        $rows = [];

        foreach ($this->inbound->findBy(['conversation' => $conversation]) as $event) {
            $rows[] = ['at' => $event->getReceivedAt(), 'item' => [
                'type' => 'customer',
                'id' => $event->getId()?->toRfc4122(),
                'subject' => $event->getSubject(),
                'body' => $event->getBody(),
                'sender' => $event->getSenderRaw(),
                'attachments' => $event->getAttachments(),
            ]];
        }

        foreach ($this->outbound->findBy(['conversation' => $conversation]) as $message) {
            $rows[] = ['at' => $message->getCreatedAt(), 'item' => [
                'type' => $message->getKind() === OutboundMessageKind::Forward ? 'forward' : 'message',
                'id' => $message->getId()?->toRfc4122(),
                'subject' => $message->getSubject(),
                'body' => $message->getBody(),
                'recipient' => $message->getRecipientRaw(),
                'author' => $this->userIri($message->getCreatedByUser()),
                'status' => $message->getStatus()->value,
                'attachments' => $message->getAttachments(),
            ]];
        }

        foreach ($this->notes->findBy(['conversation' => $conversation]) as $note) {
            $rows[] = ['at' => $note->getCreatedAt(), 'item' => [
                'type' => 'note',
                'id' => $note->getId()?->toRfc4122(),
                'body' => $note->getBody(),
                'author' => $this->userIri($note->getCreatedByUser()),
                'isPinned' => $note->isPinned(),
            ]];
        }

        usort($rows, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return array_map(
            static fn (array $row): array => ['at' => $row['at']->format(\DateTimeInterface::ATOM)] + $row['item'],
            $rows,
        );
    }

    private function userIri(?User $user): ?string
    {
        return $user !== null ? '/v1/users/' . $user->getId()?->toRfc4122() : null;
    }
}
