<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Channels\Adapter\Zabbix\ZabbixAdapter;
use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\CustomerSystem;
use App\Entity\Enum\ConversationStatus;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\InboundEvent;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Repository\ConversationRepository;
use App\Repository\CustomerSystemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * POST /v1/conversations/{id}/link-system — assign a Zabbix host to a customer.
 * Covers all three modes, the persistent CustomerSystem mapping, the back-fill
 * onto sibling host threads, and the voter guard for non-members.
 *
 * Same one-kernel / rolled-back-transaction isolation as {@see FeedbackTest}.
 */
final class ConversationLinkSystemTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        if ($conn->isTransactionActive()) {
            $conn->rollBack();
        }
        parent::tearDown();
    }

    public function testAssignToExistingCustomerCreatesMappingAndBackfills(): void
    {
        $ws = $this->workspace('zbx-a');
        $user = $this->member($this->user('zbx.a@example.test'), $ws);
        $channel = $this->channel($ws);
        $customer = $this->customer($ws, 'ACME GmbH');
        $primary = $this->conversation($channel, 'zabbix:55:20', 'web1 — CPU high');
        $this->eventFor($channel, $primary, 'web1 (Prod)');
        $sibling = $this->conversation($channel, 'zabbix:55:21', 'web1 — Disk full');
        $this->em->flush();

        $this->post("/v1/conversations/{$primary->getId()?->toRfc4122()}/link-system", $this->token($user), [
            'mode' => 'customer',
            'customer' => $customer->getId()?->toRfc4122(),
        ]);

        self::assertSame(201, $this->code());
        $this->em->clear();

        $system = self::getContainer()->get(CustomerSystemRepository::class)
            ->findByExternalRef($this->em->find(Workspace::class, $ws->getId()), ZabbixAdapter::CODE, '55');
        self::assertNotNull($system);
        self::assertSame($customer->getId()?->toRfc4122(), $system->getCustomer()->getId()?->toRfc4122());

        // Both the primary and the sibling host thread adopted the customer.
        $repo = self::getContainer()->get(ConversationRepository::class);
        self::assertNotNull($repo->find($primary->getId())->getCustomer());
        self::assertNotNull($repo->find($sibling->getId())->getCustomer());
    }

    public function testAssignToExistingSystemStampsExternalRef(): void
    {
        $ws = $this->workspace('zbx-b');
        $user = $this->member($this->user('zbx.b@example.test'), $ws);
        $channel = $this->channel($ws);
        $customer = $this->customer($ws, 'Beta AG');
        $system = (new CustomerSystem())->setCustomer($customer)->setName('Beta TYPO3');
        $this->em->persist($system);
        $conv = $this->conversation($channel, 'zabbix:88:40', 'db1 — Load');
        $this->eventFor($channel, $conv, 'db1 (Prod)');
        $this->em->flush();

        $this->post("/v1/conversations/{$conv->getId()?->toRfc4122()}/link-system", $this->token($user), [
            'mode' => 'existing',
            'system' => $system->getId()?->toRfc4122(),
        ]);

        self::assertSame(201, $this->code());
        $this->em->clear();

        $reloaded = $this->em->find(CustomerSystem::class, $system->getId());
        self::assertSame(ZabbixAdapter::CODE, $reloaded->getExternalSource());
        self::assertSame('88', $reloaded->getExternalId());
    }

    public function testAssignToNewCustomer(): void
    {
        $ws = $this->workspace('zbx-c');
        $user = $this->member($this->user('zbx.c@example.test'), $ws);
        $channel = $this->channel($ws);
        $conv = $this->conversation($channel, 'zabbix:99:50', 'app1 — 5xx');
        $this->eventFor($channel, $conv, 'app1 (Prod)');
        $this->em->flush();

        $this->post("/v1/conversations/{$conv->getId()?->toRfc4122()}/link-system", $this->token($user), [
            'mode' => 'create',
            'newCustomerName' => 'Frisch GmbH',
        ]);

        self::assertSame(201, $this->code());
        $this->em->clear();

        $system = self::getContainer()->get(CustomerSystemRepository::class)
            ->findByExternalRef($this->em->find(Workspace::class, $ws->getId()), ZabbixAdapter::CODE, '99');
        self::assertNotNull($system);
        self::assertSame('Frisch GmbH', $system->getCustomer()->getName());
    }

    public function testNonMemberIsDenied(): void
    {
        $ws = $this->workspace('zbx-d');
        $this->member($this->user('zbx.d@example.test'), $ws);
        $outsider = $this->user('outsider@example.test'); // no membership
        $channel = $this->channel($ws);
        $customer = $this->customer($ws, 'Gamma');
        $conv = $this->conversation($channel, 'zabbix:11:12', 'x');
        $this->em->flush();

        $this->post("/v1/conversations/{$conv->getId()?->toRfc4122()}/link-system", $this->token($outsider), [
            'mode' => 'customer',
            'customer' => $customer->getId()?->toRfc4122(),
        ]);

        self::assertSame(403, $this->code());
    }

    // ---- fixtures + helpers ---------------------------------------

    private function workspace(string $slugPrefix): Workspace
    {
        $ws = (new Workspace())
            ->setName('ZBX ' . $slugPrefix)
            ->setSlug($slugPrefix . '-' . substr(Uuid::v7()->toRfc4122(), 0, 8));
        $this->em->persist($ws);

        return $ws;
    }

    private function user(string $email): User
    {
        $user = (new User())->setEmail($email)->setFirstName('T')->setLastName('User')->setRoles([]);
        $user->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function member(User $user, Workspace $ws): User
    {
        $m = (new WorkspaceMember())->setUser($user)->setWorkspace($ws)->setRole(WorkspaceMemberRole::Member);
        $this->em->persist($m);

        return $user;
    }

    private function customer(Workspace $ws, string $name): Customer
    {
        $customer = (new Customer())->setWorkspace($ws)->setName($name);
        $this->em->persist($customer);

        return $customer;
    }

    private function channel(Workspace $ws): Channel
    {
        $channel = (new Channel())
            ->setName('Zabbix')
            ->setAdapterCode(ZabbixAdapter::CODE)
            ->setWorkspace($ws)
            ->setInboundConfig(['baseUrl' => 'https://monitoring1.example.test']);
        $this->em->persist($channel);

        return $channel;
    }

    private function conversation(Channel $channel, string $threadKey, string $subject): Conversation
    {
        $conv = (new Conversation())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setThreadKey($threadKey)
            ->setSubject($subject)
            ->setStatus(ConversationStatus::Open);
        $this->em->persist($conv);

        return $conv;
    }

    private function eventFor(Channel $channel, Conversation $conv, string $hostVisibleName): InboundEvent
    {
        $event = (new InboundEvent())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setExternalId('evt-' . substr(Uuid::v7()->toRfc4122(), 0, 12))
            ->setConversation($conv)
            ->setSourceMetadata(['hostVisibleName' => $hostVisibleName]);
        $this->em->persist($event);

        return $event;
    }

    private function token(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, string $token, array $body): void
    {
        $this->client->request('POST', $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => 'api.worktide.ddev.site',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }
}
