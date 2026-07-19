<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Channels\AdapterRegistry;
use App\Entity\Channel;
use App\Entity\Enum\InboundEventState;
use App\Entity\InboundEvent;
use App\Message\ProcessInboundEventMessage;
use App\MessageHandler\ProcessInboundEventHandler;
use App\Repository\ChannelRepository;
use App\Repository\ContactRepository;
use App\Repository\OutboundMessageRepository;
use App\Service\Inbound\AutoReplyResponder;
use App\Service\Inbound\ContactResolver;
use App\Service\Inbound\InboundEventProcessor;
use App\Service\Inbound\MailRelevanceClassifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

/**
 * Pins the at-least-once-safety contract of the inbound processing handler:
 * only Pending events are processed (+ flushed), already-settled events are a
 * no-op on redelivery, and a vanished row is dropped unrecoverably.
 *
 * Uses the real {@see InboundEventProcessor} (no external deps in the skeleton)
 * and asserts on the observable outcome — event state + whether flush ran —
 * rather than mocking the final service.
 */
final class ProcessInboundEventHandlerTest extends TestCase
{
    public function testMissingEventIsDroppedUnrecoverably(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $em->expects(self::never())->method('flush');

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handler($em)(new ProcessInboundEventMessage(Uuid::v7()));
    }

    public function testAlreadySettledEventIsSkipped(): void
    {
        $event = $this->event(InboundEventState::Processed);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($event);
        $em->expects(self::never())->method('flush'); // no work, no write

        $this->handler($em)(new ProcessInboundEventMessage(Uuid::v7()));

        // Redelivery must not re-settle / re-run the pipeline.
        self::assertSame(InboundEventState::Processed, $event->getState());
    }

    public function testPendingEventIsProcessedAndFlushed(): void
    {
        $event = $this->event(InboundEventState::Pending);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($event);
        $em->expects(self::once())->method('flush');

        $this->handler($em)(new ProcessInboundEventMessage(Uuid::v7()));

        self::assertSame(InboundEventState::Processed, $event->getState());
    }

    private function handler(EntityManagerInterface $em): ProcessInboundEventHandler
    {
        $contacts = $this->createStub(ContactRepository::class);
        $contacts->method('findOneByWorkspaceAndEmail')->willReturn(null);

        return new ProcessInboundEventHandler(
            $em,
            new InboundEventProcessor(
                new NullLogger(),
                new ContactResolver($contacts, $this->createStub(ChannelRepository::class)),
                new MailRelevanceClassifier(),
                $this->createStub(MessageBusInterface::class),
                // Empty registry → no threader for the channel; threading is
                // covered by InboundEventProcessorTest.
                new AdapterRegistry([], [], []),
                new RateLimiterFactory(['id' => 'ai_auto_suggest', 'policy' => 'no_limit'], new InMemoryStorage()),
                new AutoReplyResponder(
                    new MailRelevanceClassifier(),
                    $this->createStub(OutboundMessageRepository::class),
                    $this->createStub(MessageBusInterface::class),
                    $em,
                    new NullLogger(),
                ),
            ),
            new NullLogger(),
        );
    }

    private function event(InboundEventState $state): InboundEvent
    {
        return (new InboundEvent())
            ->setChannel((new Channel())->setAdapterCode('email_imap'))
            ->setState($state);
    }
}
