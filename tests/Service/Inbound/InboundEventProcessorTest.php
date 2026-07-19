<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbound;

use App\Channels\AdapterRegistry;
use App\Channels\Adapter\Email\MailThreader;
use App\Entity\Channel;
use App\Entity\Enum\InboundEventState;
use App\Entity\InboundEvent;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
use App\Repository\ContactRepository;
use App\Repository\ConversationRepository;
use App\Repository\InboundEventRepository;
use App\Repository\OutboundMessageRepository;
use App\Service\Inbound\AutoReplyResponder;
use App\Service\Inbound\ContactResolver;
use App\Service\Inbound\InboundEventProcessor;
use App\Service\Inbound\MailRelevanceClassifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Threading moved out of the mail adapters into the processor so the pull never
 * writes a Conversation (single-writer). This pins that the processor threads a
 * mail event via the channel's registered threader: a fresh event with no
 * matching thread gets a brand-new Conversation (keyed by its own Message-ID)
 * and is settled to Processed.
 */
final class InboundEventProcessorTest extends TestCase
{
    public function testThreadsMailEventOntoNewConversation(): void
    {
        $workspace = new Workspace();
        $channel = (new Channel())->setAdapterCode('email_imap')->setWorkspace($workspace);
        $event = (new InboundEvent())
            ->setChannel($channel)
            ->setExternalId('root-msg@example.com')
            ->setState(InboundEventState::Pending);

        // No existing thread → the threader creates a Conversation.
        $conversations = $this->createStub(ConversationRepository::class);
        $conversations->method('findByThreadKey')->willReturn(null);
        $threader = new MailThreader(
            $conversations,
            $this->createStub(InboundEventRepository::class),
            $this->createStub(EntityManagerInterface::class),
        );

        $processor = new InboundEventProcessor(
            new NullLogger(),
            new ContactResolver($this->createStub(ContactRepository::class), $this->createStub(ChannelRepository::class)),
            new MailRelevanceClassifier(),
            $this->createStub(MessageBusInterface::class),
            new AdapterRegistry([], [], [$threader], [], [], ['email_imap' => 0]),
            new RateLimiterFactory(['id' => 'ai_auto_suggest', 'policy' => 'no_limit'], new InMemoryStorage()),
            $this->autoReplyResponder(),
        );

        $processor->process($event, live: false);

        self::assertNotNull($event->getConversation());
        self::assertSame('root-msg@example.com', $event->getConversation()->getThreadKey());
        self::assertSame($channel, $event->getConversation()->getChannel());
        self::assertSame(InboundEventState::Processed, $event->getState());
    }

    public function testNonThreadingChannelLeavesConversationNull(): void
    {
        $channel = (new Channel())->setAdapterCode('generic_webhook')->setWorkspace(new Workspace());
        $event = (new InboundEvent())
            ->setChannel($channel)
            ->setExternalId('evt-1')
            ->setState(InboundEventState::Pending);

        $processor = new InboundEventProcessor(
            new NullLogger(),
            new ContactResolver($this->createStub(ContactRepository::class), $this->createStub(ChannelRepository::class)),
            new MailRelevanceClassifier(),
            $this->createStub(MessageBusInterface::class),
            new AdapterRegistry([], [], []), // no threaders registered
            new RateLimiterFactory(['id' => 'ai_auto_suggest', 'policy' => 'no_limit'], new InMemoryStorage()),
            $this->autoReplyResponder(),
        );

        $processor->process($event, live: false);

        self::assertNull($event->getConversation());
        self::assertSame(InboundEventState::Processed, $event->getState());
    }

    /**
     * Real responder with stubbed deps — process(live: false) never invokes it,
     * so behaviour is covered separately in {@see AutoReplyResponderTest}.
     */
    private function autoReplyResponder(): AutoReplyResponder
    {
        return new AutoReplyResponder(
            new MailRelevanceClassifier(),
            $this->createStub(OutboundMessageRepository::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
    }
}
