<?php

declare(strict_types=1);

namespace App\Tests\Functional\Portal;

use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\NewsletterConsentSource;
use App\Entity\Enum\NewsletterFrequency;
use App\Entity\Newsletter;
use App\Entity\NewsletterSubscription;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\NewsletterSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Portal newsletter consent tracking: subscribing stamps consentedAt +
 * consentSource, unsubscribing SOFT-revokes (keeps the row for the audit trail),
 * and re-subscribing reactivates the SAME row. Also checks the list DTO exposes
 * the estimated frequency + its localised label. Runs in a rolled-back transaction.
 */
final class PortalNewsletterConsentTest extends WebTestCase
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

    public function testSubscribeStampsConsentThenSoftRevokeThenReactivate(): void
    {
        $ctx = $this->seed();
        $token = $this->token($ctx['portalUser']);
        $subUri = '/v1/portal/newsletters/' . $ctx['nodeId'] . '/subscription';

        // List: node visible, not yet subscribed, frequency label localised (de).
        $this->request('GET', '/v1/portal/newsletters', $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $node = $this->json()['newsletters'][0];
        self::assertFalse($node['subscribed']);
        self::assertSame('monthly', $node['estimatedFrequency']);
        self::assertSame('monatlich', $node['estimatedFrequencyLabel']);

        // Subscribe → consent stamped.
        $this->request('POST', $subUri, $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->json()['subscribed']);

        $sub = $this->reloadSubscription($ctx);
        self::assertNotNull($sub);
        self::assertTrue($sub->isActive());
        self::assertNull($sub->getRevokedAt());
        self::assertSame(NewsletterConsentSource::Portal, $sub->getConsentSource());
        $firstConsentId = $sub->getId()?->toRfc4122();
        $firstConsentedAt = $sub->getConsentedAt();

        // Unsubscribe → soft revoke: row kept, revokedAt stamped.
        $this->request('DELETE', $subUri, $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->json()['subscribed']);

        $sub = $this->reloadSubscription($ctx);
        self::assertNotNull($sub, 'row retained after unsubscribe');
        self::assertFalse($sub->isActive());
        self::assertNotNull($sub->getRevokedAt());

        // List reflects it as off again.
        $this->request('GET', '/v1/portal/newsletters', $token);
        self::assertFalse($this->json()['newsletters'][0]['subscribed']);

        // Re-subscribe → SAME row reactivated (not a second row), fresh consent.
        $this->request('POST', $subUri, $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $sub = $this->reloadSubscription($ctx);
        self::assertNotNull($sub);
        self::assertTrue($sub->isActive());
        self::assertNull($sub->getRevokedAt());
        self::assertSame($firstConsentId, $sub->getId()?->toRfc4122(), 'same row reused');
        self::assertGreaterThanOrEqual($firstConsentedAt, $sub->getConsentedAt());

        // Exactly one row exists for this pair (unique-constrained, reused).
        $count = $this->em->getRepository(NewsletterSubscription::class)
            ->count(['newsletter' => $this->em->find(Newsletter::class, Uuid::fromString($ctx['nodeId']))]);
        self::assertSame(1, $count);
    }

    public function testArchivedHiddenAndNonSubscribableRendersAsHeader(): void
    {
        $ws = (new Workspace())->setName('Meta WS')->setSlug('meta-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')->setTimezone('Europe/Berlin')
            ->setSettings(['portal' => ['enabled' => true, 'features' => ['newsletters' => true]]]);
        $this->em->persist($ws);

        $category = (new Newsletter())->setTitle('Kategorie')->setIsSubscribable(false); // structure-only
        $category->setWorkspace($ws);
        $this->em->persist($category);
        $this->em->flush();

        $child = (new Newsletter())->setTitle('Produkt')->setParent($category); // subscribable leaf
        $this->em->persist($child);

        $archived = (new Newsletter())->setTitle('Alt')->setIsArchived(true);
        $archived->setWorkspace($ws);
        $this->em->persist($archived);
        $this->em->flush();

        $customer = (new Customer())->setWorkspace($ws)->setName('Kunde')->setIsCompany(true)
            ->setStatus(CustomerStatus::Active)->setPortalEnabled(true)
            ->setEnabledNewsletterIds([
                $category->getId()->toRfc4122(),
                $child->getId()->toRfc4122(),
                $archived->getId()->toRfc4122(),
            ]);
        $this->em->persist($customer);

        $portalUser = (new User())->setEmail('meta.contact@example.test')->setFirstName('T')->setLastName('U')
            ->setRoles(['ROLE_PORTAL']);
        $portalUser->setPassword('noop');
        $this->em->persist($portalUser);
        $this->em->persist(
            (new Contact())->setCustomer($customer)->setFirstName('M')->setLastName('C')
                ->setEmail('meta.contact@example.test')->setLinkedUser($portalUser),
        );
        $this->em->flush();
        $this->em->clear();

        $this->request('GET', '/v1/portal/newsletters', $this->token($portalUser));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $roots = $this->json()['newsletters'];

        // Archived node is gone; only the category remains at the root.
        self::assertCount(1, $roots);
        self::assertSame('Kategorie', $roots[0]['title']);
        // Non-subscribable category renders as a header with its subscribable child.
        self::assertFalse($roots[0]['subscribable']);
        self::assertCount(1, $roots[0]['children']);
        self::assertSame('Produkt', $roots[0]['children'][0]['title']);
        self::assertTrue($roots[0]['children'][0]['subscribable']);
    }

    public function testMandatoryNodeIsForcedOnAndMailsAllContacts(): void
    {
        $ws = (new Workspace())->setName('Mand WS')->setSlug('mand-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')->setTimezone('Europe/Berlin')
            ->setSettings(['portal' => ['enabled' => true, 'features' => ['newsletters' => true]]]);
        $this->em->persist($ws);

        $node = (new Newsletter())->setTitle('Service-Ansagen')->setIsMandatory(true);
        $node->setWorkspace($ws);
        $this->em->persist($node);
        $this->em->flush();

        $customer = (new Customer())->setWorkspace($ws)->setName('Kunde')->setIsCompany(true)
            ->setStatus(CustomerStatus::Active)->setPortalEnabled(true)
            ->setEnabledNewsletterIds([$node->getId()->toRfc4122()]);
        $this->em->persist($customer);

        $portalUser = (new User())->setEmail('mand.contact@example.test')->setFirstName('T')->setLastName('U')
            ->setRoles(['ROLE_PORTAL']);
        $portalUser->setPassword('noop');
        $this->em->persist($portalUser);
        // Two active, emailable contacts — neither has a subscription row.
        $this->em->persist(
            (new Contact())->setCustomer($customer)->setFirstName('A')->setLastName('A')
                ->setEmail('mand.contact@example.test')->setLinkedUser($portalUser),
        );
        $this->em->persist(
            (new Contact())->setCustomer($customer)->setFirstName('B')->setLastName('B')
                ->setEmail('mand.b@example.test'),
        );
        $this->em->flush();
        $nodeId = $node->getId()->toRfc4122();
        $this->em->clear();

        $token = $this->token($portalUser);

        // Portal shows it forced on, not togglable.
        $this->request('GET', '/v1/portal/newsletters', $token);
        $dto = $this->json()['newsletters'][0];
        self::assertTrue($dto['mandatory']);
        self::assertTrue($dto['subscribed']);
        self::assertFalse($dto['subscribable']);

        // (Un)subscribe on a mandatory node is a 409.
        $this->request('POST', '/v1/portal/newsletters/' . $nodeId . '/subscription', $token);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->request('DELETE', '/v1/portal/newsletters/' . $nodeId . '/subscription', $token);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());

        // Recipients = both active contacts, no subscription rows involved.
        $node = $this->em->find(Newsletter::class, Uuid::fromString($nodeId));
        $recipients = self::getContainer()->get(NewsletterSubscriptionRepository::class)
            ->findActiveRecipientsForNewsletter($node);
        self::assertCount(2, $recipients);
    }

    private function reloadSubscription(array $ctx): ?NewsletterSubscription
    {
        $this->em->clear();

        return self::getContainer()->get(NewsletterSubscriptionRepository::class)->findOneForContact(
            $this->em->find(Newsletter::class, Uuid::fromString($ctx['nodeId'])),
            $this->em->find(Contact::class, Uuid::fromString($ctx['contactId'])),
        );
    }

    /**
     * @return array{portalUser: User, nodeId: string, contactId: string}
     */
    private function seed(): array
    {
        $ws = (new Workspace())
            ->setName('NL WS')
            ->setSlug('nl-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings(['portal' => ['enabled' => true, 'features' => ['newsletters' => true]]]);
        $this->em->persist($ws);

        $node = (new Newsletter())->setTitle('Produkt-News');
        $node->setWorkspace($ws);
        $node->setEstimatedFrequency(NewsletterFrequency::Monthly);
        $this->em->persist($node);
        $this->em->flush(); // assign node id for the customer grant

        $customer = (new Customer())->setWorkspace($ws)->setName('Kunde')->setIsCompany(true)
            ->setStatus(CustomerStatus::Active)->setPortalEnabled(true)
            ->setEnabledNewsletterIds([$node->getId()->toRfc4122()]);
        $this->em->persist($customer);

        $portalUser = (new User())->setEmail('nl.contact@example.test')->setFirstName('T')->setLastName('U')
            ->setRoles(['ROLE_PORTAL']);
        $portalUser->setPassword('noop');
        $this->em->persist($portalUser);

        $contact = (new Contact())->setCustomer($customer)->setFirstName('Erika')->setLastName('Muster')
            ->setEmail('nl.contact@example.test')->setLinkedUser($portalUser);
        $this->em->persist($contact);

        $this->em->flush();
        $ids = [
            'portalUser' => $portalUser,
            'nodeId' => $node->getId()->toRfc4122(),
            'contactId' => $contact->getId()->toRfc4122(),
        ];
        $this->em->clear();

        return $ids;
    }

    private function request(string $method, string $uri, ?string $token = null): void
    {
        $server = ['HTTP_HOST' => self::HOST, 'CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request($method, $uri, [], [], $server);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
    }

    private function token(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
