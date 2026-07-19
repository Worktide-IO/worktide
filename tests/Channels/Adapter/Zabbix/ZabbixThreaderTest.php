<?php

declare(strict_types=1);

namespace App\Tests\Channels\Adapter\Zabbix;

use App\Channels\Adapter\Zabbix\ZabbixAdapter;
use App\Channels\Adapter\Zabbix\ZabbixThreader;
use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\CustomerSystem;
use App\Entity\Enum\ConversationStatus;
use App\Entity\InboundEvent;
use App\Entity\Workspace;
use App\Repository\ConversationRepository;
use App\Repository\CustomerSystemRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the Zabbix conversation-threading strategy: one thread per
 * host+trigger, recovery closes it, and the host→customer auto-link via a
 * CustomerSystem carrying the zabbix external reference.
 */
final class ZabbixThreaderTest extends TestCase
{
    public function testCreatesConversationForNewHostTrigger(): void
    {
        $conversations = $this->createStub(ConversationRepository::class);
        $conversations->method('findByThreadKey')->willReturn(null);
        $systems = $this->createStub(CustomerSystemRepository::class);
        $systems->method('findByExternalRef')->willReturn(null);

        $threader = new ZabbixThreader($conversations, $systems, $this->createStub(EntityManagerInterface::class));
        $channel = $this->channel();
        $event = $this->event($channel, resolved: false);

        $conversation = $threader->attach($channel, $event);

        self::assertSame('zabbix:55:20', $conversation->getThreadKey());
        self::assertSame(ConversationStatus::Open, $conversation->getStatus());
        self::assertSame('web1 (Prod) — CPU high', $conversation->getSubject());
        self::assertNull($conversation->getCustomer());
        self::assertSame($conversation, $event->getConversation());
    }

    public function testJoinsExistingThread(): void
    {
        $channel = $this->channel();
        $existing = (new Conversation())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setThreadKey('zabbix:55:20')
            ->setSubject('web1 (Prod) — CPU high')
            ->setStatus(ConversationStatus::Open);

        $conversations = $this->createStub(ConversationRepository::class);
        $conversations->method('findByThreadKey')->willReturn($existing);
        $systems = $this->createStub(CustomerSystemRepository::class);
        $systems->method('findByExternalRef')->willReturn(null);

        $threader = new ZabbixThreader($conversations, $systems, $this->createStub(EntityManagerInterface::class));
        $event = $this->event($channel, resolved: false);

        $conversation = $threader->attach($channel, $event);

        self::assertSame($existing, $conversation);
        self::assertSame($existing, $event->getConversation());
    }

    public function testRecoveryClosesThread(): void
    {
        $channel = $this->channel();
        $existing = (new Conversation())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setThreadKey('zabbix:55:20')
            ->setSubject('web1 (Prod) — CPU high')
            ->setStatus(ConversationStatus::Open);

        $conversations = $this->createStub(ConversationRepository::class);
        $conversations->method('findByThreadKey')->willReturn($existing);
        $systems = $this->createStub(CustomerSystemRepository::class);
        $systems->method('findByExternalRef')->willReturn(null);

        $threader = new ZabbixThreader($conversations, $systems, $this->createStub(EntityManagerInterface::class));
        $event = $this->event($channel, resolved: true);

        $conversation = $threader->attach($channel, $event);

        self::assertSame(ConversationStatus::Closed, $conversation->getStatus());
    }

    public function testAutoLinksCustomerFromCustomerSystem(): void
    {
        $channel = $this->channel();
        $customer = (new Customer())->setWorkspace($channel->getWorkspace())->setName('ACME GmbH');
        $system = (new CustomerSystem())
            ->setCustomer($customer)
            ->setName('web1')
            ->setExternalSource(ZabbixAdapter::CODE)
            ->setExternalId('55');

        $conversations = $this->createStub(ConversationRepository::class);
        $conversations->method('findByThreadKey')->willReturn(null);
        $systems = $this->createStub(CustomerSystemRepository::class);
        $systems->method('findByExternalRef')->willReturn($system);

        $threader = new ZabbixThreader($conversations, $systems, $this->createStub(EntityManagerInterface::class));
        $event = $this->event($channel, resolved: false);

        $conversation = $threader->attach($channel, $event);

        self::assertSame($customer, $conversation->getCustomer());
    }

    private function channel(): Channel
    {
        return (new Channel())
            ->setName('Zabbix')
            ->setAdapterCode(ZabbixAdapter::CODE)
            ->setWorkspace(new Workspace());
    }

    private function event(Channel $channel, bool $resolved): InboundEvent
    {
        return (new InboundEvent())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setExternalId($resolved ? 'resolved:100' : '100')
            ->setSubject('CPU high')
            ->setSenderRaw('web1 (Prod)')
            ->setSourceMetadata([
                'hostid' => '55',
                'triggerId' => '20',
                'hostVisibleName' => 'web1 (Prod)',
                'resolved' => $resolved,
            ]);
    }
}
