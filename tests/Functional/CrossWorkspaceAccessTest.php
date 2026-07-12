<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Channel;
use App\Entity\Customer;
use App\Entity\Webhook;
use App\Entity\WebhookDelivery;
use App\Entity\Workspace;
use App\Tests\Support\TenantFixtureTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase-T mechanism proof: {@see \App\ApiPlatform\Doctrine\WorkspaceScopeExtension}
 * actually BLOCKS cross-tenant reads/writes — the complement to
 * {@see \App\Tests\Architecture\ApiResourceScopingTest}, which only proves every
 * resource is *covered*.
 *
 * A member of workspace A, holding a valid token, must never reach a workspace-B
 * resource: it is absent from the collection, 404 on item GET (scoped away →
 * "not found", no existence disclosure), and 404 on PATCH by foreign id.
 *
 * Proven on two independent trait-scoped resources (Customer + Channel) so the
 * guarantee reads as generic, not Customer-special. The extension is generic;
 * the exhaustive per-resource coverage is the architecture test's job.
 */
final class CrossWorkspaceAccessTest extends WebTestCase
{
    use TenantFixtureTrait;

    protected function setUp(): void
    {
        $this->bootTenant();
    }

    protected function tearDown(): void
    {
        $this->rollbackTenant();
        parent::tearDown();
    }

    public function testCustomerIsNotReadableAcrossWorkspaces(): void
    {
        [$aliceToken, $ownId, $foreignId] = $this->seedCustomers();

        // Collection: only A's customer; B's never leaks.
        $this->apiGet('/v1/customers', $aliceToken);
        self::assertSame(200, $this->responseStatus());
        $ids = $this->collectionIds();
        self::assertContains($ownId, $ids);
        self::assertNotContains($foreignId, $ids);

        // Item: the foreign-workspace customer is scoped away → 404, not 403/200.
        $this->apiGet('/v1/customers/' . $foreignId, $aliceToken);
        self::assertSame(404, $this->responseStatus());

        // Own item is readable (proves the 404 above is isolation, not a broken fixture).
        $this->apiGet('/v1/customers/' . $ownId, $aliceToken);
        self::assertSame(200, $this->responseStatus());
    }

    public function testCustomerIsNotPatchableAcrossWorkspaces(): void
    {
        [$aliceToken, , $foreignId] = $this->seedCustomers();

        // PATCH by foreign id: the scope extension zeroes the item before the
        // security voter even runs → 404 (never a silent cross-tenant write).
        $this->apiPatch('/v1/customers/' . $foreignId, $aliceToken, ['name' => 'Hijacked']);
        self::assertSame(404, $this->responseStatus());

        // The row is unchanged in the DB.
        $foreign = $this->em->getRepository(Customer::class)->find($foreignId);
        self::assertInstanceOf(Customer::class, $foreign);
        self::assertNotSame('Hijacked', $foreign->getName());
    }

    public function testChannelIsNotReadableAcrossWorkspaces(): void
    {
        [$aliceToken, $ownId, $foreignId] = $this->seedChannels();

        $this->apiGet('/v1/channels', $aliceToken);
        self::assertSame(200, $this->responseStatus());
        $ids = $this->collectionIds();
        self::assertContains($ownId, $ids);
        self::assertNotContains($foreignId, $ids);

        $this->apiGet('/v1/channels/' . $foreignId, $aliceToken);
        self::assertSame(404, $this->responseStatus());
    }

    /**
     * Regression for the confirmed leak: WebhookDelivery has no workspace column
     * of its own and its collection was `is_granted('ROLE_USER')`-only, so it
     * used to return every tenant's deliveries (incl. responseBody/errorMessage).
     * Now scoped via WorkspaceScopeExtension::PARENT_SCOPED (Webhook.workspace).
     * Stands in for the other three parent-scoped children (identical mechanism).
     */
    public function testWebhookDeliveryCollectionIsScopedToWorkspace(): void
    {
        $wsA = $this->makeWorkspace('wda');
        $wsB = $this->makeWorkspace('wdb');
        $alice = $this->makeUser('alice.wd@example.test');
        $this->makeMember($alice, $wsA);

        $own = $this->webhookDelivery($wsA, 'own response');
        $foreign = $this->webhookDelivery($wsB, 'secret B response');
        $this->em->flush();
        $ownId = $own->getId()?->toRfc4122() ?? '';
        $foreignId = $foreign->getId()?->toRfc4122() ?? '';
        $token = $this->jwt($alice);
        $this->em->clear();

        $this->apiGet('/v1/webhook_deliveries', $token);
        self::assertSame(200, $this->responseStatus());
        $ids = $this->collectionIds();
        self::assertContains($ownId, $ids, 'own workspace delivery should be visible');
        self::assertNotContains($foreignId, $ids, 'foreign workspace delivery must not leak');

        // Body must not carry the other tenant's response payload either.
        self::assertStringNotContainsString('secret B response', $this->rawBody());
    }

    private function webhookDelivery(Workspace $ws, string $responseBody): WebhookDelivery
    {
        $hook = (new Webhook())->setWorkspace($ws);
        $hook->setName('Hook')->setUrl('https://example.com/hook')->setSecret('s');
        $this->em->persist($hook);
        $delivery = (new WebhookDelivery())
            ->setWebhook($hook)
            ->setEventName('task.created')
            ->setResponseBody($responseBody);
        $this->em->persist($delivery);

        return $delivery;
    }

    /**
     * @return array{0: string, 1: string, 2: string} [aliceToken, ownCustomerId, foreignCustomerId]
     */
    private function seedCustomers(): array
    {
        $wsA = $this->makeWorkspace('xwa');
        $wsB = $this->makeWorkspace('xwb');
        $alice = $this->makeUser('alice.xw@example.test');
        $this->makeMember($alice, $wsA);

        $own = $this->makeCustomer($wsA, 'Kunde A');
        $foreign = $this->makeCustomer($wsB, 'Kunde B');
        $this->em->flush();

        $ids = [$this->jwt($alice), $own->getId()?->toRfc4122() ?? '', $foreign->getId()?->toRfc4122() ?? ''];
        $this->em->clear();

        return $ids;
    }

    /**
     * @return array{0: string, 1: string, 2: string} [aliceToken, ownChannelId, foreignChannelId]
     */
    private function seedChannels(): array
    {
        $wsA = $this->makeWorkspace('xca');
        $wsB = $this->makeWorkspace('xcb');
        $alice = $this->makeUser('alice.xc@example.test');
        $this->makeMember($alice, $wsA);

        $own = $this->channel($wsA, 'A-Mailbox');
        $foreign = $this->channel($wsB, 'B-Mailbox');
        $this->em->flush();

        $ids = [$this->jwt($alice), $own->getId()?->toRfc4122() ?? '', $foreign->getId()?->toRfc4122() ?? ''];
        $this->em->clear();

        return $ids;
    }

    private function channel(\App\Entity\Workspace $ws, string $name): Channel
    {
        // Shared so it is visible to any workspace member (ChannelVisibilityExtension);
        // the point of the test is that B's channel is invisible to an A member.
        $channel = (new Channel())
            ->setWorkspace($ws)
            ->setName($name)
            ->setAdapterCode('email_imap')
            ->setIsShared(true);
        $this->em->persist($channel);

        return $channel;
    }
}
