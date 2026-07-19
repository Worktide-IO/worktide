<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbound;

use App\Entity\Channel;
use App\Entity\Enum\ChannelCapability;
use App\Entity\InboundEvent;
use App\Entity\OutboundMessage;
use App\Entity\Workspace;
use App\Message\SendOutboundMessage;
use App\Repository\OutboundMessageRepository;
use App\Service\Inbound\AutoReplyResponder;
use App\Service\Inbound\MailRelevanceClassifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class AutoReplyResponderTest extends TestCase
{
    public function testQueuesAutoReplyForActionableMail(): void
    {
        $channel = $this->enabledChannel();
        $event = $this->event($channel, 'Kunde <kunde@example.com>', 'Hilfe bitte');

        $persisted = null;
        $em = $this->em(function (object $e) use (&$persisted): void { $persisted = $e; });

        $dispatched = [];
        $bus = $this->bus($dispatched);

        $this->responder($em, $bus, throttled: false)->maybeReply($event);

        self::assertInstanceOf(OutboundMessage::class, $persisted);
        self::assertSame('kunde@example.com', $persisted->getRecipientRaw());
        self::assertSame('Re: Hilfe bitte', $persisted->getSubject());
        self::assertSame('Wir haben Ihre Nachricht erhalten.', $persisted->getBody());
        self::assertSame('<p>Wir haben Ihre Nachricht erhalten.</p>', $persisted->getBodyHtml());
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(SendOutboundMessage::class, $dispatched[0]);
    }

    public function testSkipsWhenDisabled(): void
    {
        $channel = $this->enabledChannel()->setAutoReplyEnabled(false);
        $event = $this->event($channel, 'kunde@example.com', 'Hallo');

        $this->assertNoReply($channel, $event, throttled: false);
    }

    public function testSkipsBulkMail(): void
    {
        $channel = $this->enabledChannel();
        $event = $this->event($channel, 'newsletter@example.com', 'Angebot')
            ->setSourceMetadata(['headers' => ['List-Unsubscribe' => '<mailto:x@y.z>']]);

        $this->assertNoReply($channel, $event, throttled: false);
    }

    public function testSkipsWhenThrottled(): void
    {
        $channel = $this->enabledChannel();
        $event = $this->event($channel, 'kunde@example.com', 'Nochmal');

        $this->assertNoReply($channel, $event, throttled: true);
    }

    public function testSkipsSelfAddressedMail(): void
    {
        $channel = $this->enabledChannel(); // address = support@firma.de
        $event = $this->event($channel, 'support@firma.de', 'Loop');

        $this->assertNoReply($channel, $event, throttled: false);
    }

    public function testSkipsWhenSenderInvalid(): void
    {
        $channel = $this->enabledChannel();
        $event = $this->event($channel, 'not-an-email', 'Kaputt');

        $this->assertNoReply($channel, $event, throttled: false);
    }

    // ---- helpers ----------------------------------------------------

    private function assertNoReply(Channel $channel, InboundEvent $event, bool $throttled): void
    {
        $persisted = false;
        $em = $this->em(function () use (&$persisted): void { $persisted = true; });
        $dispatched = [];
        $bus = $this->bus($dispatched);

        $this->responder($em, $bus, $throttled)->maybeReply($event);

        self::assertFalse($persisted, 'no OutboundMessage should be persisted');
        self::assertCount(0, $dispatched);
    }

    private function enabledChannel(): Channel
    {
        return (new Channel())
            ->setAdapterCode('email_imap')
            ->setWorkspace(new Workspace())
            ->setAddress('support@firma.de')
            ->setCapabilities([ChannelCapability::Outbound])
            ->setAutoReplyEnabled(true)
            ->setAutoReplySubject(null)
            ->setAutoReplyBodyText('Wir haben Ihre Nachricht erhalten.')
            ->setAutoReplyBodyHtml('<p>Wir haben Ihre Nachricht erhalten.</p>')
            ->setAutoReplyThrottleHours(24);
    }

    private function event(Channel $channel, string $senderRaw, string $subject): InboundEvent
    {
        return (new InboundEvent())
            ->setChannel($channel)
            ->setExternalId('msg-' . bin2hex(random_bytes(4)))
            ->setSenderRaw($senderRaw)
            ->setSubject($subject);
    }

    private function responder(EntityManagerInterface $em, MessageBusInterface $bus, bool $throttled): AutoReplyResponder
    {
        $repo = $this->createStub(OutboundMessageRepository::class);
        $repo->method('hasRecentAutoReply')->willReturn($throttled);

        return new AutoReplyResponder(new MailRelevanceClassifier(), $repo, $bus, $em, new NullLogger());
    }

    /** EM stub whose persist() assigns a Uuid id like Doctrine's generator. */
    private function em(callable $onPersist): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use ($onPersist): void {
            $ref = new \ReflectionProperty($entity, 'id');
            if ($ref->getValue($entity) === null) {
                $ref->setValue($entity, Uuid::v7());
            }
            $onPersist($entity);
        });

        return $em;
    }

    /** @param list<object> $captured */
    private function bus(array &$captured): MessageBusInterface
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$captured): Envelope {
            $captured[] = $msg;

            return new Envelope($msg);
        });

        return $bus;
    }
}
