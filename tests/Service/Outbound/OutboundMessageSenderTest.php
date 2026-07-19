<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound;

use App\Channels\AdapterRegistry;
use App\Channels\OutboundAdapter;
use App\Channels\OutboundResult;
use App\Entity\Channel;
use App\Entity\Enum\OutboundMessageStatus;
use App\Entity\OutboundMessage;
use App\Egress\EgressGuard;
use App\Service\Outbound\OutboundMessageSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OutboundMessageSenderTest extends TestCase
{
    public function testWithheldWhenEgressBlocked(): void
    {
        $adapter = $this->recordingAdapter(OutboundResult::sent('should-not-happen'));
        $sender = new OutboundMessageSender(
            new AdapterRegistry([], [$adapter], []),
            new EgressGuard(''), // nothing allowed
            new NullLogger(),
        );

        $message = $this->queuedMessage();
        $ok = $sender->send($message);

        self::assertFalse($ok);
        self::assertSame(OutboundMessageStatus::Queued, $message->getStatus(), 'stays queued for after approval');
        self::assertFalse($adapter->called, 'adapter must not be hit when egress is blocked');
    }

    public function testSendsWhenEgressAllowed(): void
    {
        $adapter = $this->recordingAdapter(OutboundResult::sent('ext-123'));
        $sender = new OutboundMessageSender(
            new AdapterRegistry([], [$adapter], []),
            new EgressGuard('email_outbound'),
            new NullLogger(),
        );

        $message = $this->queuedMessage();
        $ok = $sender->send($message);

        self::assertTrue($ok);
        self::assertTrue($adapter->called);
        self::assertSame(OutboundMessageStatus::Sent, $message->getStatus());
        self::assertSame('ext-123', $message->getExternalId());
        self::assertNotNull($message->getSentAt());
        self::assertSame(1, $message->getAttemptCount());
    }

    public function testPermanentFailureMarksFailed(): void
    {
        $adapter = $this->recordingAdapter(OutboundResult::failed('bad recipient'));
        $sender = new OutboundMessageSender(
            new AdapterRegistry([], [$adapter], []),
            new EgressGuard('email_outbound'),
            new NullLogger(),
        );

        $message = $this->queuedMessage();
        $ok = $sender->send($message);

        self::assertFalse($ok);
        self::assertSame(OutboundMessageStatus::Failed, $message->getStatus());
        self::assertSame('bad recipient', $message->getStatusReason());
    }

    public function testAlreadySentIsNoop(): void
    {
        $adapter = $this->recordingAdapter(OutboundResult::sent('x'));
        $sender = new OutboundMessageSender(
            new AdapterRegistry([], [$adapter], []),
            new EgressGuard('email_outbound'),
            new NullLogger(),
        );

        $message = $this->queuedMessage()->setStatus(OutboundMessageStatus::Sent);
        $ok = $sender->send($message);

        self::assertTrue($ok);
        self::assertFalse($adapter->called, 'a Sent message is not re-sent');
    }

    private function queuedMessage(): OutboundMessage
    {
        $channel = (new Channel())->setAdapterCode('email_imap');

        return (new OutboundMessage())
            ->setChannel($channel)
            ->setRecipientRaw('kunde@example.com')
            ->setBody('Danke für Ihre Nachricht.')
            ->setStatus(OutboundMessageStatus::Queued);
    }

    private function recordingAdapter(OutboundResult $result): OutboundAdapter
    {
        return new class($result) implements OutboundAdapter {
            public bool $called = false;

            public function __construct(private readonly OutboundResult $result) {}

            public function getCode(): string { return 'email_imap'; }
            public function getLabel(): string { return 'Test'; }

            public function send(Channel $channel, OutboundMessage $message): OutboundResult
            {
                $this->called = true;

                return $this->result;
            }
        };
    }
}
