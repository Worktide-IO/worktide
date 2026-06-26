<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Conversation;
use App\Entity\ConversationNote;
use App\Entity\Enum\OutboundMessageKind;
use App\Entity\Enum\OutboundMessageStatus;
use App\Entity\InboundEvent;
use App\Entity\OutboundMessage;
use App\Repository\ConversationNoteRepository;
use App\Repository\InboundEventRepository;
use App\Repository\OutboundMessageRepository;
use App\Service\ConversationTimeline;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The thread merge orders the three sources chronologically and maps each to
 * its thread type (customer / forward / message / note).
 */
final class ConversationTimelineTest extends TestCase
{
    public function testMergesSourcesChronologicallyWithCorrectTypes(): void
    {
        // Out of order on purpose: forward (10:00) < customer (11:00) < note (12:00).
        $outbound = $this->outbound(new \DateTimeImmutable('2026-01-01 10:00'), OutboundMessageKind::Forward);
        $inbound = $this->inbound(new \DateTimeImmutable('2026-01-01 11:00'));
        $note = $this->note(new \DateTimeImmutable('2026-01-01 12:00'));

        $timeline = new ConversationTimeline(
            $this->repo(InboundEventRepository::class, [$inbound]),
            $this->repo(OutboundMessageRepository::class, [$outbound]),
            $this->repo(ConversationNoteRepository::class, [$note]),
        );

        $items = $timeline->build($this->createStub(Conversation::class));

        self::assertCount(3, $items);
        self::assertSame(['forward', 'customer', 'note'], array_column($items, 'type'));
        // ATOM offset reflects the app's default timezone; assert ascending order.
        $timestamps = array_map(
            static fn (string $s): int => (new \DateTimeImmutable($s))->getTimestamp(),
            array_column($items, 'at'),
        );
        $sorted = $timestamps;
        sort($sorted);
        self::assertSame($sorted, $timestamps, 'chronologically ascending');
    }

    public function testReplyKindMapsToMessage(): void
    {
        $timeline = new ConversationTimeline(
            $this->repo(InboundEventRepository::class, []),
            $this->repo(OutboundMessageRepository::class, [
                $this->outbound(new \DateTimeImmutable('2026-01-01 09:00'), OutboundMessageKind::Reply),
            ]),
            $this->repo(ConversationNoteRepository::class, []),
        );

        $items = $timeline->build($this->createStub(Conversation::class));

        self::assertSame('message', $items[0]['type']);
    }

    private function inbound(\DateTimeImmutable $at): InboundEvent
    {
        $e = $this->createStub(InboundEvent::class);
        $e->method('getReceivedAt')->willReturn($at);
        $e->method('getId')->willReturn(Uuid::v7());
        $e->method('getSubject')->willReturn('Help');
        $e->method('getBody')->willReturn('customer text');
        $e->method('getSenderRaw')->willReturn('cust@x.test');
        $e->method('getAttachments')->willReturn([]);

        return $e;
    }

    private function outbound(\DateTimeImmutable $at, OutboundMessageKind $kind): OutboundMessage
    {
        $m = $this->createStub(OutboundMessage::class);
        $m->method('getCreatedAt')->willReturn($at);
        $m->method('getKind')->willReturn($kind);
        $m->method('getStatus')->willReturn(OutboundMessageStatus::Queued);
        $m->method('getId')->willReturn(Uuid::v7());
        $m->method('getSubject')->willReturn('Re: Help');
        $m->method('getBody')->willReturn('agent text');
        $m->method('getRecipientRaw')->willReturn('cust@x.test');
        $m->method('getCreatedByUser')->willReturn(null);
        $m->method('getAttachments')->willReturn([]);

        return $m;
    }

    private function note(\DateTimeImmutable $at): ConversationNote
    {
        $n = $this->createStub(ConversationNote::class);
        $n->method('getCreatedAt')->willReturn($at);
        $n->method('getId')->willReturn(Uuid::v7());
        $n->method('getBody')->willReturn('internal note');
        $n->method('getCreatedByUser')->willReturn(null);
        $n->method('isPinned')->willReturn(false);

        return $n;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param list<object>     $items
     *
     * @return T
     */
    private function repo(string $class, array $items): object
    {
        $repo = $this->createStub($class);
        $repo->method('findBy')->willReturn($items);

        return $repo;
    }
}
