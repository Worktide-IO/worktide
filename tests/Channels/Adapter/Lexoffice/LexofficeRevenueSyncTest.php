<?php

declare(strict_types=1);

namespace App\Tests\Channels\Adapter\Lexoffice;

use App\Channels\Adapter\LexofficeAdapter;
use App\Channels\SyncReentryGuard;
use App\Entity\Channel;
use App\Entity\Customer;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\SyncMode;
use App\Entity\EntitySync;
use App\Entity\Workspace;
use App\Repository\CustomerRepository;
use App\Repository\EntitySyncRepository;
use App\Repository\InvoiceRepository;
use App\Service\Inbound\DiscoveredRecordCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

/**
 * Regression coverage for the cloud2 disk-space incident (2026-08-01):
 * `worktide:channel:pull` runs every 2 minutes and, for any channel with
 * "revenue" in entityTypes, used to unconditionally re-sum lexoffice
 * invoices and re-write every mapped Customer's revenueCents/revenueSyncedAt
 * — even when nothing changed — generating a domain_events row + binlog
 * write per customer on every single tick.
 *
 * {@see LexofficeAdapter::runRevenueSync()} now (a) skips the whole sync
 * unless {@see Channel::isRevenueSyncFresh()} says it's stale, and (b) only
 * touches a Customer row when the computed cents value actually changed.
 */
final class LexofficeRevenueSyncTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
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

    public function testSecondPullWithinIntervalSkipsEntirelyNoHttpNoWrite(): void
    {
        $callCount = 0;
        [$channel, $customer] = $this->seed();

        $adapter = $this->adapter($this->countingClient($callCount, 12345.0));
        $adapter->pull($channel);
        $this->em->flush();
        $this->em->refresh($customer);
        $this->em->refresh($channel);

        self::assertSame(1234500, $customer->getRevenueCents());
        $firstSyncedAt = $customer->getRevenueSyncedAt();
        self::assertNotNull($firstSyncedAt);
        self::assertNotNull($channel->getRevenueSyncedAt());
        self::assertSame(1, $callCount, 'first pull should hit lexoffice once');

        // Immediate second pull (simulates the 2-minute cron re-tick):
        // must be skipped entirely — no HTTP call, no DB write.
        $adapter->pull($channel);
        $this->em->refresh($customer);

        self::assertSame(1, $callCount, 'a fresh revenue sync must not call lexoffice again');
        self::assertEquals($firstSyncedAt, $customer->getRevenueSyncedAt(), 'unchanged revenueSyncedAt when the sync was skipped');
    }

    public function testStaleReSyncWithUnchangedRevenueDoesNotRewriteCustomer(): void
    {
        $callCount = 0;
        [$channel, $customer] = $this->seed();

        $adapter = $this->adapter($this->countingClient($callCount, 12345.0));
        $adapter->pull($channel);
        $this->em->flush();
        $this->em->refresh($customer);
        $firstSyncedAt = $customer->getRevenueSyncedAt();

        // Force the throttle window open, as if REVENUE_SYNC_INTERVAL_MINUTES
        // had elapsed since the first sync.
        $channel->setRevenueSyncedAt(new \DateTimeImmutable('-1 year'));
        $this->em->flush();

        $adapter->pull($channel);
        $this->em->refresh($customer);
        $this->em->refresh($channel);

        self::assertSame(2, $callCount, 'a stale revenue sync must re-fetch from lexoffice');
        self::assertSame(1234500, $customer->getRevenueCents(), 'value unchanged');
        self::assertEquals($firstSyncedAt, $customer->getRevenueSyncedAt(), 'unchanged value must not bump revenueSyncedAt (no domain_events/binlog write)');
        self::assertGreaterThanOrEqual($firstSyncedAt, $channel->getRevenueSyncedAt(), 'the channel-level throttle marker still advances');
    }

    public function testStaleReSyncWithChangedRevenueDoesRewriteCustomer(): void
    {
        $callCount = 0;
        [$channel, $customer] = $this->seed();

        $adapter = $this->adapter($this->countingClient($callCount, 12345.0));
        $adapter->pull($channel);
        $this->em->flush();
        $this->em->refresh($customer);
        $firstSyncedAt = $customer->getRevenueSyncedAt();

        $channel->setRevenueSyncedAt(new \DateTimeImmutable('-1 year'));
        $this->em->flush();

        $adapter2 = $this->adapter($this->countingClient($callCount, 99900.0));
        $adapter2->pull($channel);
        $this->em->refresh($customer);

        self::assertSame(9990000, $customer->getRevenueCents());
        self::assertGreaterThanOrEqual($firstSyncedAt, $customer->getRevenueSyncedAt(), 'a real change still bumps revenueSyncedAt');
    }

    // ---- fixtures ------------------------------------------------------

    /** @return array{0: Channel, 1: Customer} */
    private function seed(): array
    {
        $workspace = new Workspace();
        $workspace->setName('Lexoffice Test WS')->setSlug('lexoffice-test-ws-' . bin2hex(random_bytes(4)));
        $this->em->persist($workspace);

        $channel = new Channel();
        $channel->setWorkspace($workspace)
            ->setName('Lexoffice')
            ->setAdapterCode('lexoffice')
            ->setEntityTypes(['revenue'])
            ->setIsEnabled(true)
            ->setAuthConfig(['apiKey' => 'test-key']);
        $this->em->persist($channel);

        $customer = new Customer();
        $customer->setWorkspace($workspace)
            ->setIsCompany(true)
            ->setStatus(CustomerStatus::Active)
            ->setName('Acme GmbH')
            ->setExternalSource('lexoffice')
            ->setExternalId('lx-contact-1');
        $this->em->persist($customer);
        $this->em->flush();

        $sync = new EntitySync();
        $sync->setChannel($channel)
            ->setWorkspace($workspace)
            ->setEntityType('customer')
            ->setEntityId($customer->getId())
            ->setExternalId('lx-contact-1')
            ->setSyncMode(SyncMode::Inbound);
        $this->em->persist($sync);
        $this->em->flush();

        // ChannelAuthConfigCipherListener::prePersist encrypts authConfig leaf
        // values in-place on the same in-memory object; reload so getAuthConfig()
        // returns plain strings again (postLoad decrypts).
        $this->em->refresh($channel);

        return [$channel, $customer];
    }

    private function countingClient(int &$callCount, float $totalAmount): MockHttpClient
    {
        return new MockHttpClient(function () use (&$callCount, $totalAmount): MockResponse {
            $callCount++;

            return new MockResponse(json_encode([
                'content' => [
                    ['contactId' => 'lx-contact-1', 'totalAmount' => $totalAmount, 'voucherDate' => '2026-01-01'],
                ],
                'last' => true,
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
    }

    private function adapter(MockHttpClient $http): LexofficeAdapter
    {
        $c = self::getContainer();

        return new LexofficeAdapter(
            $this->em,
            $http,
            $c->get(CustomerRepository::class),
            $c->get(EntitySyncRepository::class),
            $c->get(InvoiceRepository::class),
            $c->get(SyncReentryGuard::class),
            $c->get(DiscoveredRecordCollector::class),
        );
    }
}
