<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\Contact;
use App\Entity\Conversation;
use App\Entity\InboundEvent;
use App\Repository\ContactRepository;

/**
 * Auto-resolve: matches an inbound message's sender email to a known
 * {@see Contact} in the workspace and enriches the event + its conversation
 * with the customer context (Phase C Schicht 4). Called from
 * {@see InboundEventProcessor}.
 *
 * Resolution is lookup-only — an unknown sender is left unresolved rather than
 * spawning a junk Contact/Customer. When a match is found:
 *   - the event's `senderContact` is set (if not already), and
 *   - the conversation's `customer` is set (if the thread had none).
 */
final class ContactResolver
{
    public function __construct(
        private readonly ContactRepository $contacts,
    ) {}

    public function resolveForEvent(InboundEvent $event): ?Contact
    {
        $email = $this->extractEmail((string) $event->getSenderRaw());
        if ($email === null) {
            return null;
        }

        $contact = $this->contacts->findOneByWorkspaceAndEmail($event->getWorkspace(), $email);
        if ($contact === null) {
            return null;
        }

        if ($event->getSenderContact() === null) {
            $event->setSenderContact($contact);
        }

        $conversation = $event->getConversation();
        if ($conversation !== null && $conversation->getCustomer() === null) {
            $conversation->setCustomer($contact->getCustomer());
        }

        return $contact;
    }

    /**
     * Same lookup for an existing {@see Conversation} (backfill / re-resolve):
     * match its stored sender against a known Contact and adopt that Contact's
     * Customer when the thread has none. Lookup-only, idempotent — a thread that
     * already has a customer, has no sender, or whose sender is unknown is left
     * untouched. Shares the matching rules with {@see resolveForEvent()}.
     */
    public function resolveForConversation(Conversation $conversation): ?Contact
    {
        if ($conversation->getCustomer() !== null) {
            return null;
        }

        $email = $this->extractEmail((string) $conversation->getSenderRaw());
        if ($email === null) {
            return null;
        }

        $contact = $this->contacts->findOneByWorkspaceAndEmail($conversation->getWorkspace(), $email);
        if ($contact === null) {
            return null;
        }

        $conversation->setCustomer($contact->getCustomer());

        return $contact;
    }

    /** Pull a bare email out of a raw `Name <email>` / `email` from-header. */
    private function extractEmail(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        if (preg_match('/<([^>]+)>/', $raw, $m) === 1) {
            $raw = $m[1];
        }
        $raw = trim($raw);

        return filter_var($raw, \FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($raw) : null;
    }
}
