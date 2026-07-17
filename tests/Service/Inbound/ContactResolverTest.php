<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbound;

use App\Entity\Contact;
use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\InboundEvent;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
use App\Repository\ContactRepository;
use App\Service\Inbound\ContactResolver;
use PHPUnit\Framework\TestCase;

/**
 * Auto-resolve: a matching sender enriches the event + conversation; an unknown,
 * malformed, automated or own-account sender is left untouched (no junk
 * Contact/Customer, no self / loop-back mis-link).
 */
final class ContactResolverTest extends TestCase
{
    /** Channels repo that reports the workspace owns no addresses (default). */
    private function noOwnAddresses(): ChannelRepository
    {
        $channels = $this->createStub(ChannelRepository::class);
        $channels->method('findOwnAddresses')->willReturn([]);

        return $channels;
    }

    public function testMatchSetsSenderContactAndConversationCustomer(): void
    {
        $workspace = new Workspace();
        $customer = $this->createStub(Customer::class);
        $contact = $this->createStub(Contact::class);
        $contact->method('getCustomer')->willReturn($customer);

        $contacts = $this->createStub(ContactRepository::class);
        $contacts->method('findOneByWorkspaceAndEmail')->willReturn($contact);

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getCustomer')->willReturn(null);
        $conversation->expects(self::once())->method('setCustomer')->with($customer);

        $event = $this->createMock(InboundEvent::class);
        $event->method('getSenderRaw')->willReturn('Ada Lovelace <ada@x.test>');
        $event->method('getWorkspace')->willReturn($workspace);
        $event->method('getSenderContact')->willReturn(null);
        $event->method('getConversation')->willReturn($conversation);
        $event->expects(self::once())->method('setSenderContact')->with($contact);

        self::assertSame($contact, (new ContactResolver($contacts, $this->noOwnAddresses()))->resolveForEvent($event));
    }

    public function testUnknownSenderResolvesToNull(): void
    {
        $contacts = $this->createStub(ContactRepository::class);
        $contacts->method('findOneByWorkspaceAndEmail')->willReturn(null);

        $event = $this->createMock(InboundEvent::class);
        $event->method('getSenderRaw')->willReturn('stranger@x.test');
        $event->method('getWorkspace')->willReturn(new Workspace());
        $event->expects(self::never())->method('setSenderContact');

        self::assertNull((new ContactResolver($contacts, $this->noOwnAddresses()))->resolveForEvent($event));
    }

    public function testResolveForConversationAdoptsContactsCustomer(): void
    {
        $customer = $this->createStub(Customer::class);
        $contact = $this->createStub(Contact::class);
        $contact->method('getCustomer')->willReturn($customer);

        $contacts = $this->createStub(ContactRepository::class);
        $contacts->method('findOneByWorkspaceAndEmail')->willReturn($contact);

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getCustomer')->willReturn(null);
        $conversation->method('getSenderRaw')->willReturn('Ada Lovelace <ada@x.test>');
        $conversation->method('getWorkspace')->willReturn(new Workspace());
        $conversation->expects(self::once())->method('setCustomer')->with($customer);

        self::assertSame($contact, (new ContactResolver($contacts, $this->noOwnAddresses()))->resolveForConversation($conversation));
    }

    public function testResolveForConversationLeavesAssignedThreadUntouched(): void
    {
        // Already has a customer → never re-queries, never overwrites (idempotent backfill).
        $contacts = $this->createMock(ContactRepository::class);
        $contacts->expects(self::never())->method('findOneByWorkspaceAndEmail');

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getCustomer')->willReturn($this->createStub(Customer::class));
        $conversation->expects(self::never())->method('setCustomer');

        self::assertNull((new ContactResolver($contacts, $this->noOwnAddresses()))->resolveForConversation($conversation));
    }

    public function testResolveForConversationUnknownSenderResolvesToNull(): void
    {
        $contacts = $this->createStub(ContactRepository::class);
        $contacts->method('findOneByWorkspaceAndEmail')->willReturn(null);

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getCustomer')->willReturn(null);
        $conversation->method('getSenderRaw')->willReturn('stranger@x.test');
        $conversation->method('getWorkspace')->willReturn(new Workspace());
        $conversation->expects(self::never())->method('setCustomer');

        self::assertNull((new ContactResolver($contacts, $this->noOwnAddresses()))->resolveForConversation($conversation));
    }

    public function testMalformedSenderShortCircuits(): void
    {
        $contacts = $this->createMock(ContactRepository::class);
        $contacts->expects(self::never())->method('findOneByWorkspaceAndEmail');

        $event = $this->createMock(InboundEvent::class);
        $event->method('getSenderRaw')->willReturn('not-an-email');
        $event->method('getWorkspace')->willReturn(new Workspace());
        $event->expects(self::never())->method('setSenderContact');

        self::assertNull((new ContactResolver($contacts, $this->noOwnAddresses()))->resolveForEvent($event));
    }

    public function testConfiguredIgnoredSenderNeverMatches(): void
    {
        // Env-configured shared address / alias that sends on behalf of the real
        // person — must never resolve to a Contact even if one carries it.
        $contacts = $this->createMock(ContactRepository::class);
        $contacts->expects(self::never())->method('findOneByWorkspaceAndEmail');

        $event = $this->createMock(InboundEvent::class);
        $event->method('getSenderRaw')->willReturn('WapplerSystems Kontaktformular <info@wappler.systems>');
        $event->method('getWorkspace')->willReturn(new Workspace());
        $event->expects(self::never())->method('setSenderContact');

        $resolver = new ContactResolver($contacts, $this->noOwnAddresses(), ' INFO@Wappler.Systems , kontakt@example.com ');
        self::assertNull($resolver->resolveForEvent($event));
    }

    public function testAutomatedRoleLocalPartNeverMatches(): void
    {
        // no-reply / mailer-daemon etc. are always ignored, no config needed.
        $contacts = $this->createMock(ContactRepository::class);
        $contacts->expects(self::never())->method('findOneByWorkspaceAndEmail');

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getCustomer')->willReturn(null);
        $conversation->method('getSenderRaw')->willReturn('Mailer <no-reply@some-crm.test>');
        $conversation->method('getWorkspace')->willReturn(new Workspace());
        $conversation->expects(self::never())->method('setCustomer');

        self::assertNull((new ContactResolver($contacts, $this->noOwnAddresses()))->resolveForConversation($conversation));
    }

    public function testWorkspaceOwnChannelAddressNeverMatches(): void
    {
        // Sender == one of the workspace's own Channel addresses → self / loop-back
        // mail (contact-form / invoice mailer). Denied without env config.
        $contacts = $this->createMock(ContactRepository::class);
        $contacts->expects(self::never())->method('findOneByWorkspaceAndEmail');

        $channels = $this->createStub(ChannelRepository::class);
        $channels->method('findOwnAddresses')->willReturn(['info@wappler.systems']);

        $event = $this->createMock(InboundEvent::class);
        $event->method('getSenderRaw')->willReturn('WapplerSystems <Info@Wappler.Systems>');
        $event->method('getWorkspace')->willReturn(new Workspace());
        $event->expects(self::never())->method('setSenderContact');

        self::assertNull((new ContactResolver($contacts, $channels))->resolveForEvent($event));
    }
}
