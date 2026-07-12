<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Newsletter;
use App\Entity\NewsletterSubscription;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Phase E: /v1/reports/newsletter-subscriber-counts tallies subscriptions per
 * newsletter into active / pending / revoked buckets, workspace-scoped.
 */
final class NewsletterReportsTest extends WebTestCase
{
    private const HOST = 'api.worktide.ddev.site';

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

    public function testSubscriberCountsByState(): void
    {
        $ws = (new Workspace())->setName('Rep WS')->setSlug('rep-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')->setTimezone('Europe/Berlin')->setSettings([]);
        $this->em->persist($ws);

        $owner = (new User())->setEmail('rep.owner-' . substr(Uuid::v7()->toRfc4122(), 0, 8) . '@example.test')
            ->setFirstName('O')->setLastName('W')->setRoles([]);
        $owner->setPassword('x');
        $this->em->persist($owner);
        $this->em->persist((new WorkspaceMember())->setWorkspace($ws)->setUser($owner)->setRole(WorkspaceMemberRole::Owner));

        $node = (new Newsletter())->setTitle('Produkt-News');
        $node->setWorkspace($ws);
        $this->em->persist($node);
        $this->em->flush();

        $customer = (new Customer())->setWorkspace($ws)->setName('Kunde')->setIsCompany(true)
            ->setStatus(CustomerStatus::Active);
        $this->em->persist($customer);

        // 2 active (confirmed), 1 pending, 1 revoked.
        $this->sub($node, $customer, 'a1@example.test', 'active');
        $this->sub($node, $customer, 'a2@example.test', 'active');
        $this->sub($node, $customer, 'p1@example.test', 'pending');
        $this->sub($node, $customer, 'r1@example.test', 'revoked');
        $this->em->flush();
        $nodeIri = '/v1/newsletters/' . $node->getId()->toRfc4122();
        $wsId = $ws->getId()->toRfc4122();

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($owner);
        $this->client->request('GET', '/v1/reports/newsletter-subscriber-counts', [], [], [
            'HTTP_HOST' => self::HOST,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_X_WORKSPACE_ID' => $wsId,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $counts = json_decode((string) $this->client->getResponse()->getContent(), true)['counts'];

        self::assertArrayHasKey($nodeIri, $counts);
        self::assertSame(2, $counts[$nodeIri]['active']);
        self::assertSame(1, $counts[$nodeIri]['pending']);
        self::assertSame(1, $counts[$nodeIri]['revoked']);
    }

    private function sub(Newsletter $node, Customer $customer, string $email, string $state): void
    {
        $contact = (new Contact())->setCustomer($customer)->setFirstName('C')->setLastName('C')->setEmail($email);
        $this->em->persist($contact);
        $sub = (new NewsletterSubscription())->setNewsletter($node)->setContact($contact);
        if ($state === 'active') {
            $sub->confirm();
        } elseif ($state === 'revoked') {
            $sub->confirm();
            $sub->revoke();
        }
        // 'pending' → leave unconfirmed, not revoked.
        $this->em->persist($sub);
    }
}
