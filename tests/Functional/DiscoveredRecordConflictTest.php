<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Channel;
use App\Entity\Customer;
use App\Entity\DiscoveredExternalRecord;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\DiscoveredRecordState;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\EntitySync;
use App\Entity\Workspace;
use App\Tests\Support\TenantFixtureTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Conflict review-inbox resolution: an ambiguous-match DiscoveredExternalRecord
 * (entityType=customer, non-empty candidates) is resolved by the operator either
 * by linking to a chosen candidate or creating a new customer, each writing the
 * missing EntitySync mapping; a second action on a settled record is a 409.
 */
final class DiscoveredRecordConflictTest extends WebTestCase
{
    use TenantFixtureTrait;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->bootTenant();
    }

    protected function tearDown(): void
    {
        $this->rollbackTenant();
    }

    public function testResolveLinkBindsChosenCandidate(): void
    {
        [$token, $record, $c1] = $this->seedConflict();
        $recordId = $record->getId()->toRfc4122();
        $externalId = $record->getExternalId();

        $this->apiRequest(
            'POST',
            '/v1/discovered_external_records/' . $recordId . '/resolve-link',
            $token,
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['target' => (string) $c1->getId()], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $this->responseStatus(), $this->rawBody());

        $this->em->clear();
        $reloaded = $this->em->find(DiscoveredExternalRecord::class, \Symfony\Component\Uid\Uuid::fromString($recordId));
        self::assertSame(DiscoveredRecordState::Linked, $reloaded->getState());
        self::assertSame([], $reloaded->getCandidates(), 'candidates cleared on resolution');

        $sync = $this->em->getRepository(EntitySync::class)->findOneBy(['externalId' => $externalId]);
        self::assertNotNull($sync, 'EntitySync mapping written');
        self::assertSame((string) $c1->getId(), (string) $sync->getEntityId());
    }

    public function testResolveCreateMakesNewCustomer(): void
    {
        [$token, $record] = $this->seedConflict();
        $recordId = $record->getId()->toRfc4122();

        $this->apiRequest(
            'POST',
            '/v1/discovered_external_records/' . $recordId . '/resolve-create',
            $token,
            ['CONTENT_TYPE' => 'application/json'],
        );

        self::assertSame(201, $this->responseStatus(), $this->rawBody());
        $body = $this->jsonBody();
        self::assertSame('imported', $body['state']);
        self::assertNotEmpty($body['customerId']);

        $this->em->clear();
        $created = $this->em->find(Customer::class, \Symfony\Component\Uid\Uuid::fromString($body['customerId']));
        self::assertNotNull($created);
        self::assertSame('Ambig GmbH', $created->getName());
        self::assertSame('lexoffice', $created->getExternalSource());
    }

    public function testSecondActionYields409(): void
    {
        [$token, $record, $c1] = $this->seedConflict();
        $recordId = $record->getId()->toRfc4122();
        $uri = '/v1/discovered_external_records/' . $recordId . '/resolve-link';
        $body = json_encode(['target' => (string) $c1->getId()], \JSON_THROW_ON_ERROR);

        $this->apiRequest('POST', $uri, $token, ['CONTENT_TYPE' => 'application/json'], $body);
        self::assertSame(200, $this->responseStatus());

        // Re-loading is required because the entity was detached-ish across the request.
        $this->apiRequest('POST', $uri, $token, ['CONTENT_TYPE' => 'application/json'], $body);
        self::assertSame(409, $this->responseStatus(), $this->rawBody());
    }

    /**
     * @return array{0: string, 1: DiscoveredExternalRecord, 2: Customer, 3: Customer}
     */
    private function seedConflict(): array
    {
        $ws = $this->makeWorkspace('conflict');
        $user = $this->makeUser('admin+' . uniqid('', true) . '@example.test');
        $this->makeMember($user, $ws, WorkspaceMemberRole::Admin);

        $c1 = $this->makeCustomer($ws, 'Ambig GmbH');
        $c2 = $this->makeCustomer($ws, 'Ambig GmbH (Zweitsitz)');

        $channel = (new Channel())
            ->setName('lexoffice')
            ->setAdapterCode('lexoffice')
            ->setCapabilities(['inbound'])
            ->setEntityTypes(['customer'])
            ->setIsEnabled(true)
            ->setIsShared(true);
        $channel->setWorkspace($ws);
        $this->em->persist($channel);

        // Flush first so customers/channel get ids before we reference them.
        $this->em->flush();

        $record = (new DiscoveredExternalRecord())
            ->setChannel($channel)
            ->setEntityType('customer')
            ->setExternalId('lx-ambig-1')
            ->setExternalUrl('https://app.lexoffice.de/contacts/#/lx-ambig-1')
            ->setTitle('Ambig GmbH')
            ->setFields(['name' => 'Ambig GmbH', 'isCompany' => true])
            ->setCandidates([
                ['id' => (string) $c1->getId(), 'label' => $c1->getName()],
                ['id' => (string) $c2->getId(), 'label' => $c2->getName()],
            ]);
        $record->setWorkspace($ws);
        $this->em->persist($record);

        $this->em->flush();

        return [$this->jwt($user), $record, $c1, $c2];
    }
}
