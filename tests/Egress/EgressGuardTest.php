<?php

declare(strict_types=1);

namespace App\Tests\Egress;

use App\Egress\EgressBlockedException;
use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Channel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Unit coverage for the default-deny egress gate: nothing is allowed unless
 * EGRESS_ALLOW explicitly approves it, with optional per-channel scoping.
 */
final class EgressGuardTest extends TestCase
{
    public function testEmptyBlocksEverything(): void
    {
        $guard = new EgressGuard('');

        foreach (EgressModule::cases() as $module) {
            self::assertFalse($guard->isAllowed($module), $module->value . ' must be blocked');
        }
    }

    public function testNullBlocksEverything(): void
    {
        $guard = new EgressGuard(null);

        self::assertFalse($guard->isAllowed(EgressModule::Llm));
    }

    public function testBareModuleAllowsAllChannels(): void
    {
        $guard = new EgressGuard('llm');

        self::assertTrue($guard->isAllowed(EgressModule::Llm));
        self::assertTrue($guard->isAllowed(EgressModule::Llm, $this->channelWithId(Uuid::v7()->toRfc4122())));
        // Other modules remain blocked.
        self::assertFalse($guard->isAllowed(EgressModule::TicketPush));
    }

    public function testChannelScopedAllowsOnlyThatChannel(): void
    {
        $allowed = Uuid::v7()->toRfc4122();
        $guard = new EgressGuard('ticket_push:' . $allowed);

        self::assertTrue($guard->isAllowed(EgressModule::TicketPush, $this->channelWithId($allowed)));
        self::assertFalse($guard->isAllowed(EgressModule::TicketPush, $this->channelWithId(Uuid::v7()->toRfc4122())));
        // Channel-scoped grant does not allow the unscoped (null-channel) check.
        self::assertFalse($guard->isAllowed(EgressModule::TicketPush));
    }

    public function testMultipleEntriesAndUnknownTokenIgnored(): void
    {
        $chan = Uuid::v7()->toRfc4122();
        $guard = new EgressGuard('llm, social_publish , ticket_push:' . $chan . ', bogus_module');

        self::assertTrue($guard->isAllowed(EgressModule::Llm));
        self::assertTrue($guard->isAllowed(EgressModule::SocialPublish));
        self::assertTrue($guard->isAllowed(EgressModule::TicketPush, $this->channelWithId($chan)));
        self::assertFalse($guard->isAllowed(EgressModule::WebhookDelivery));
    }

    public function testAssertAllowedThrowsWhenBlocked(): void
    {
        $this->expectException(EgressBlockedException::class);
        (new EgressGuard(''))->assertAllowed(EgressModule::Llm);
    }

    private function channelWithId(string $uuid): Channel
    {
        $channel = (new Channel())->setName('x')->setAdapterCode('redmine');
        $ref = new \ReflectionProperty(Channel::class, 'id');
        $ref->setValue($channel, Uuid::fromString($uuid));

        return $channel;
    }
}
