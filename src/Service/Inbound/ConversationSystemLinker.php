<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Channels\Adapter\Zabbix\ZabbixAdapter;
use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\CustomerSystem;
use App\Entity\InboundEvent;
use App\Repository\ConversationRepository;
use App\Repository\CustomerSystemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Assigns a monitoring host to a customer from the inbox detail view.
 *
 * The persistent host ↔ customer relation lives on a {@see CustomerSystem}
 * carrying the external reference `zabbix:<hostid>`. Once set, future Zabbix
 * alerts for that host auto-link to the customer in {@see \App\Channels\Adapter\Zabbix\ZabbixThreader}.
 * Assigning also back-fills the customer onto every existing customer-less
 * conversation of the same host, so all of a host's threads adopt it at once.
 *
 * Mirrors {@see ConversationContactLinker} (link-contact) for hosts.
 */
final class ConversationSystemLinker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationRepository $conversations,
        private readonly CustomerSystemRepository $systems,
        private readonly ConversationContactLinker $contactLinker,
    ) {}

    /** Create/repoint the host's CustomerSystem under an existing customer. */
    public function assignToCustomer(Conversation $conversation, Customer $customer): CustomerSystem
    {
        $hostId = $this->hostId($conversation);
        $system = $this->ensureSystem($conversation, $customer, $hostId);
        $this->applyCustomer($conversation, $customer, $hostId);
        $this->em->flush();

        return $system;
    }

    /** Create a customer on the fly, then assign the host to it. */
    public function assignToNewCustomer(Conversation $conversation, string $customerName): CustomerSystem
    {
        $customer = $this->contactLinker->createCustomer($conversation, $customerName);

        return $this->assignToCustomer($conversation, $customer);
    }

    /** Stamp the host reference onto an existing CustomerSystem and adopt its customer. */
    public function assignToExistingSystem(Conversation $conversation, CustomerSystem $system): CustomerSystem
    {
        $hostId = $this->hostId($conversation);
        if ($system->getExternalSource() === null && $system->getExternalId() === null) {
            $system->setExternalSource(ZabbixAdapter::CODE)->setExternalId($hostId);
        }
        $this->applyCustomer($conversation, $system->getCustomer(), $hostId);
        $this->em->flush();

        return $system;
    }

    /**
     * Find-or-create the CustomerSystem that carries this host's Zabbix
     * reference, owned by $customer. Re-points an existing mapping to the chosen
     * customer (an explicit operator re-assignment).
     */
    private function ensureSystem(Conversation $conversation, Customer $customer, string $hostId): CustomerSystem
    {
        $system = $this->systems->findByExternalRef($conversation->getWorkspace(), ZabbixAdapter::CODE, $hostId);
        if ($system !== null) {
            if ($system->getCustomer() !== $customer) {
                $system->setCustomer($customer);
            }

            return $system;
        }

        $system = (new CustomerSystem())
            ->setCustomer($customer)
            ->setName(mb_substr($this->hostName($conversation, $hostId), 0, 200))
            ->setExternalSource(ZabbixAdapter::CODE)
            ->setExternalId($hostId);
        $this->em->persist($system);

        return $system;
    }

    /** Set the customer on this + every other customer-less thread of the host. */
    private function applyCustomer(Conversation $conversation, Customer $customer, string $hostId): void
    {
        if ($conversation->getCustomer() === null) {
            $conversation->setCustomer($customer);
        }
        foreach ($this->conversations->findZabbixByHostWithoutCustomer($conversation->getChannel(), $hostId) as $thread) {
            $thread->setCustomer($customer);
        }
    }

    /** hostid out of the "zabbix:<hostid>:<triggerId>" threadKey. */
    private function hostId(Conversation $conversation): string
    {
        $parts = explode(':', $conversation->getThreadKey());
        $hostId = $parts[1] ?? '';
        if (($parts[0] ?? '') !== 'zabbix' || $hostId === '') {
            throw new \InvalidArgumentException('Conversation is not a Zabbix host thread.');
        }

        return $hostId;
    }

    /** Best-effort human host name from the thread's latest event, else the id. */
    private function hostName(Conversation $conversation, string $hostId): string
    {
        /** @var InboundEvent|null $event */
        $event = $this->em->getRepository(InboundEvent::class)
            ->findOneBy(['conversation' => $conversation], ['receivedAt' => 'DESC']);
        $name = \is_string($event?->getSourceMetadata()['hostVisibleName'] ?? null)
            ? (string) $event->getSourceMetadata()['hostVisibleName']
            : '';

        return $name !== '' ? $name : ('Zabbix Host ' . $hostId);
    }
}
