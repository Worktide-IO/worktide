<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\Contact;
use App\Entity\ContactEmail;
use App\Entity\Conversation;
use App\Entity\Customer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a conversation's sender into address-book data, driven from the inbox
 * detail view (Phase C Schicht 4). Two modes:
 *
 *   - {@see linkToExisting()}: attach the sender email to an existing Contact
 *     (as a secondary {@see ContactEmail}, or primary if the contact has none)
 *     and adopt that contact's Customer onto the thread.
 *   - {@see createContact()}: create a new Contact (under an existing or a
 *     freshly-created Customer) carrying the sender email as its primary
 *     address, and link the thread's Customer.
 *
 * The primary Contact.email mirror is maintained by
 * {@see \App\EventListener\ContactPrimaryInfoSyncListener} on flush.
 */
final class ConversationContactLinker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return array{contact: Contact, email: ?ContactEmail} */
    public function linkToExisting(Conversation $conversation, Contact $contact): array
    {
        $address = $this->senderEmail($conversation);
        $email = $this->attachEmail($contact, $address);

        if ($conversation->getCustomer() === null) {
            $conversation->setCustomer($contact->getCustomer());
        }
        $this->em->flush();

        return ['contact' => $contact, 'email' => $email];
    }

    /** @return array{contact: Contact, email: ?ContactEmail, customer: Customer} */
    public function createContact(
        Conversation $conversation,
        Customer $customer,
        string $firstName,
        string $lastName,
    ): array {
        $address = $this->senderEmail($conversation);

        $contact = (new Contact())
            ->setCustomer($customer)
            ->setFirstName($firstName !== '' ? $firstName : '—')
            ->setLastName($lastName !== '' ? $lastName : '—');
        $this->em->persist($contact);

        $email = $this->attachEmail($contact, $address);

        if ($conversation->getCustomer() === null) {
            $conversation->setCustomer($customer);
        }
        $this->em->flush();

        return ['contact' => $contact, 'email' => $email, 'customer' => $customer];
    }

    /** Create a workspace Customer on the fly (for "new contact + new customer"). */
    public function createCustomer(Conversation $conversation, string $name): Customer
    {
        $customer = (new Customer())
            ->setWorkspace($conversation->getWorkspace())
            ->setName($name !== '' ? $name : 'Neuer Kunde');
        $this->em->persist($customer);

        return $customer;
    }

    /**
     * Attach $address to $contact unless already present. Becomes the primary
     * address when the contact has none yet. Returns null when the sender had no
     * usable email (the contact/customer link still happens).
     */
    private function attachEmail(Contact $contact, ?string $address): ?ContactEmail
    {
        if ($address === null) {
            return null;
        }
        foreach ($contact->getEmails() as $existing) {
            if ($existing->getAddress() === $address) {
                return $existing;
            }
        }

        $email = (new ContactEmail())
            ->setAddress($address)
            ->setContact($contact)
            ->setPrimary($contact->getEmails()->isEmpty() && $contact->getEmail() === null);
        $contact->addEmailAddress($email);
        $this->em->persist($email);

        return $email;
    }

    /** Bare lowercased email out of the thread's `Name <email>` sender, or null. */
    private function senderEmail(Conversation $conversation): ?string
    {
        $raw = (string) $conversation->getSenderRaw();
        if ($raw !== '' && preg_match('/<([^>]+)>/', $raw, $m) === 1) {
            $raw = $m[1];
        }
        $raw = trim($raw);

        return filter_var($raw, \FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($raw) : null;
    }
}
