<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\Enum\NewsletterIssueStatus;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Newsletter;
use App\Entity\NewsletterIssue;
use App\Entity\NewsletterSubscription;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Repository\NewsletterIssueRepository;
use App\Repository\NewsletterSubscriptionRepository;
use App\Service\Newsletter\NewsletterUnsubscribeSigner;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Newsletter sending + one-click unsubscribe: recipient resolution (grant /
 * active / email filters), the send guards (auth, already-sent, egress off),
 * the happy send (marks sent + recipient count), and the signed public
 * unsubscribe token.
 */
final class NewsletterSendTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        unset($_SERVER['EGRESS_ALLOW'], $_ENV['EGRESS_ALLOW']);
        parent::tearDown();
    }

    /**
     * Boot a client, optionally with an egress module enabled. `EgressGuard`
     * reads EGRESS_ALLOW at kernel boot, so we set it before createClient rather
     * than replacing the (already-initialized) service.
     */
    private function boot(string $egress = ''): void
    {
        if ($egress !== '') {
            $_SERVER['EGRESS_ALLOW'] = $_ENV['EGRESS_ALLOW'] = $egress;
        }
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    public function testRecipientResolutionAppliesGrantActiveAndEmailFilters(): void
    {
        $this->boot();
        $ctx = $this->seed();
        // Add three non-recipients on the same node: no grant, inactive, no email.
        $ungranted = $this->customer($ctx['ws'], []); // node NOT in enabledNewsletterIds
        $this->subscribe($ctx['node'], $this->contact($ungranted, 'granted-off@example.test'));
        $inactive = $this->contact($ctx['customer'], 'inactive@example.test');
        $inactive->setIsActive(false);
        $this->subscribe($ctx['node'], $inactive);
        $this->subscribe($ctx['node'], $this->contact($ctx['customer'], null)); // no email
        $this->em->flush();

        $recipients = self::getContainer()->get(NewsletterSubscriptionRepository::class)
            ->findActiveRecipientsForNewsletter($ctx['node']);

        $emails = array_map(static fn (Contact $c) => $c->getEmail(), $recipients);
        self::assertContains('sub@example.test', $emails, 'the granted, active, emailable subscriber is included');
        self::assertNotContains('granted-off@example.test', $emails, 'ungranted customer excluded');
        self::assertNotContains('inactive@example.test', $emails, 'inactive contact excluded');
        self::assertCount(1, $recipients);
    }

    public function testSendRequiresAuth(): void
    {
        $this->boot();
        $ctx = $this->seed();
        $issue = $this->issue($ctx['node']);
        $this->client->request('POST', '/v1/newsletter_issues/' . $issue->getId()?->toRfc4122() . '/send');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testSendBlockedWhenEgressDisabled(): void
    {
        $this->boot();
        $ctx = $this->seed();
        $issue = $this->issue($ctx['node']);
        // Default test env: EGRESS_ALLOW empty → newsletter_send denied.
        $this->post('/v1/newsletter_issues/' . $issue->getId()?->toRfc4122() . '/send', $ctx['owner']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testAlreadySentIssueCannotBeResent(): void
    {
        $this->boot();
        $ctx = $this->seed();
        $issue = $this->issue($ctx['node']);
        $issue->setStatus(NewsletterIssueStatus::Sent);
        $this->em->flush();

        $this->post('/v1/newsletter_issues/' . $issue->getId()?->toRfc4122() . '/send', $ctx['owner']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testSendMarksSentAndCountsRecipients(): void
    {
        $this->boot('newsletter_send');
        $ctx = $this->seed();
        $issue = $this->issue($ctx['node']);

        $this->post('/v1/newsletter_issues/' . $issue->getId()?->toRfc4122() . '/send', $ctx['owner']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        self::assertTrue($body['sent']);
        self::assertSame(1, $body['recipientCount']);

        $this->em->clear();
        $reloaded = self::getContainer()->get(NewsletterIssueRepository::class)->find($issue->getId());
        self::assertSame(NewsletterIssueStatus::Sent, $reloaded?->getStatus());
        self::assertSame(1, $reloaded?->getRecipientCount());
        self::assertNotNull($reloaded?->getSentAt());
    }

    public function testUnsubscribeTokenRevokesSubscription(): void
    {
        $this->boot();
        $ctx = $this->seed();
        $signer = self::getContainer()->get(NewsletterUnsubscribeSigner::class);
        $token = $signer->sign($ctx['contact']->getId(), $ctx['node']->getId());

        // Info: still subscribed.
        $this->client->request('GET', '/v1/newsletter/unsubscribe/' . $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true)['unsubscribed']);

        // Do it.
        $this->client->request('POST', '/v1/newsletter/unsubscribe/' . $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['unsubscribed']);

        // Soft opt-out: the row is kept (consent audit) but marked revoked.
        $this->em->clear();
        $remaining = self::getContainer()->get(NewsletterSubscriptionRepository::class)
            ->findOneForContact(
                $this->em->find(Newsletter::class, $ctx['node']->getId()),
                $this->em->find(Contact::class, $ctx['contact']->getId()),
            );
        self::assertNotNull($remaining, 'subscription row retained for audit');
        self::assertFalse($remaining->isActive(), 'subscription no longer active');
        self::assertNotNull($remaining->getRevokedAt(), 'revokedAt stamped');

        // Info now reports unsubscribed.
        $this->client->request('GET', '/v1/newsletter/unsubscribe/' . $token);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['unsubscribed']);
    }

    public function testForgedUnsubscribeTokenIs404(): void
    {
        $this->boot();
        $this->client->request('GET', '/v1/newsletter/unsubscribe/not-a-real-token');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDescendantFanoutIncludesChildSubscribersAndDedupes(): void
    {
        $this->boot('newsletter_send');
        $ctx = $this->seed(); // node P, contact subscribed to P (customer grants P)
        $parent = $ctx['node'];

        $child = (new Newsletter())->setTitle('Unterthema');
        $child->setParent($parent); // auto-stamps workspace
        $this->em->persist($child);
        $this->em->flush();

        // The seed contact is ALSO subscribed to the child (must be deduped),
        // and its customer now grants both nodes.
        $ctx['customer']->setEnabledNewsletterIds([
            $parent->getId()->toRfc4122(),
            $child->getId()->toRfc4122(),
        ]);
        $this->subscribe($child, $ctx['contact']);
        // A second contact opted into ONLY the child.
        $childOnly = $this->contact($this->customer($ctx['ws'], [$child->getId()->toRfc4122()]), 'child-only@example.test');
        $this->subscribe($child, $childOnly);
        $this->em->flush();

        // Node-only: just the parent subscriber.
        $issue1 = $this->issue($parent);
        $this->postJson('/v1/newsletter_issues/' . $issue1->getId()?->toRfc4122() . '/send', $ctx['owner'], []);
        self::assertSame(1, $this->json()['recipientCount'], 'node-only send hits only the parent subscriber');

        // With descendants: parent subscriber (deduped though also on child) + child-only.
        $issue2 = $this->issue($parent);
        $this->postJson('/v1/newsletter_issues/' . $issue2->getId()?->toRfc4122() . '/send', $ctx['owner'], ['includeDescendants' => true]);
        self::assertSame(2, $this->json()['recipientCount'], 'fan-out adds child subscribers, deduping shared contacts');
    }

    public function testSentIssueCannotBeEdited(): void
    {
        $this->boot();
        $ctx = $this->seed();
        $issue = $this->issue($ctx['node']);
        $issue->setStatus(NewsletterIssueStatus::Sent);
        $this->em->flush();

        $this->patch('/v1/newsletter_issues/' . $issue->getId()?->toRfc4122(), $ctx['owner'], ['subject' => 'geändert']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testDraftCanBeEdited(): void
    {
        $this->boot();
        $ctx = $this->seed();
        $issue = $this->issue($ctx['node']);

        $this->patch('/v1/newsletter_issues/' . $issue->getId()?->toRfc4122(), $ctx['owner'], ['subject' => 'Neuer Betreff']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('Neuer Betreff', $this->json()['subject']);
    }

    public function testStatusIsNotClientWritable(): void
    {
        $this->boot();
        $ctx = $this->seed();

        $this->postJson(
            '/v1/newsletter_issues',
            $ctx['owner'],
            ['newsletter' => '/v1/newsletters/' . $ctx['node']->getId()?->toRfc4122(), 'subject' => 'X', 'status' => 'sent'],
            'application/ld+json',
        );
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame('draft', $this->json()['status'], 'client cannot forge the sent status');
    }

    /**
     * @return array{ws: Workspace, owner: User, customer: Customer, node: Newsletter, contact: Contact}
     */
    private function seed(): array
    {
        $ws = (new Workspace())->setName('WS')->setSlug('ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')->setTimezone('Europe/Berlin')->setSettings([]);
        $this->em->persist($ws);

        $owner = (new User())->setEmail('nl.owner-' . substr(Uuid::v7()->toRfc4122(), 0, 8) . '@example.test')
            ->setFirstName('O')->setLastName('W')->setRoles([]);
        $owner->setPassword('x');
        $this->em->persist($owner);
        $this->em->persist((new WorkspaceMember())->setWorkspace($ws)->setUser($owner)->setRole(WorkspaceMemberRole::Owner));

        $node = (new Newsletter())->setTitle('Produkt-News');
        $node->setWorkspace($ws);
        $this->em->persist($node);
        $this->em->flush(); // assign node id for the grant

        $customer = $this->customer($ws, [$node->getId()->toRfc4122()]);
        $contact = $this->contact($customer, 'sub@example.test');
        $this->subscribe($node, $contact);
        $this->em->flush();

        return ['ws' => $ws, 'owner' => $owner, 'customer' => $customer, 'node' => $node, 'contact' => $contact];
    }

    /** @param list<string> $enabledNewsletterIds */
    private function customer(Workspace $ws, array $enabledNewsletterIds): Customer
    {
        $c = (new Customer())->setName('Acme ' . substr(Uuid::v7()->toRfc4122(), 0, 6));
        $c->setWorkspace($ws);
        $c->setEnabledNewsletterIds($enabledNewsletterIds);
        $this->em->persist($c);

        return $c;
    }

    private function contact(Customer $customer, ?string $email): Contact
    {
        $contact = (new Contact())->setCustomer($customer)->setFirstName('Erika')->setLastName('Muster')->setEmail($email);
        $this->em->persist($contact);

        return $contact;
    }

    private function subscribe(Newsletter $node, Contact $contact): NewsletterSubscription
    {
        $sub = (new NewsletterSubscription())->setNewsletter($node)->setContact($contact);
        $sub->setWorkspace($node->getWorkspace());
        $this->em->persist($sub);

        return $sub;
    }

    private function issue(Newsletter $node): NewsletterIssue
    {
        $issue = (new NewsletterIssue())->setSubject('Hallo {{ firstName }}')->setBody('# News\n\nContent for **{{ company }}**.');
        $issue->setNewsletter($node);
        $this->em->persist($issue);
        $this->em->flush();

        return $issue;
    }

    private function post(string $uri, User $as): void
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($as);
        $this->client->request('POST', $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $body */
    private function postJson(string $uri, User $as, array $body, string $contentType = 'application/json'): void
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($as);
        $this->client->request('POST', $uri, [], [], [
            'CONTENT_TYPE' => $contentType,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $body */
    private function patch(string $uri, User $as, array $body): void
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($as);
        $this->client->request('PATCH', $uri, [], [], [
            'CONTENT_TYPE' => 'application/merge-patch+json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
