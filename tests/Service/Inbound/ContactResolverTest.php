<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbound;

use App\Entity\Contact;
use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\InboundEvent;
use App\Entity\Workspace;
use App\Repository\ContactRepository;
use App\Service\Inbound\ContactResolver;
use PHPUnit\Framework\TestCase;

/**
 * Auto-resolve: a matching sender enriches the event + conversation; an unknown
 * or malformed sender is left untouched (no junk Contact/Customer).
 */
final class ContactResolverTest extends TestCase
{
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

        self::assertSame($contact, (new ContactResolver($contacts))->resolveForEvent($event));
    }

    public function testUnknownSenderResolvesToNull(): void
    {
        $contacts = $this->createStub(ContactRepository::class);
        $contacts->method('findOneByWorkspaceAndEmail')->willReturn(null);

        $event = $this->createMock(InboundEvent::class);
        $event->method('getSenderRaw')->willReturn('stranger@x.test');
        $event->method('getWorkspace')->willReturn(new Workspace());
        $event->expects(self::never())->method('setSenderContact');

        self::assertNull((new ContactResolver($contacts))->resolveForEvent($event));
    }

    public function testMalformedSenderShortCircuits(): void
    {
        $contacts = $this->createMock(ContactRepository::class);
        $contacts->expects(self::never())->method('findOneByWorkspaceAndEmail');

        $event = $this->createMock(InboundEvent::class);
        $event->method('getSenderRaw')->willReturn('not-an-email');
        $event->expects(self::never())->method('setSenderContact');

        self::assertNull((new ContactResolver($contacts))->resolveForEvent($event));
    }
}
