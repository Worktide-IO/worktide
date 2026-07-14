<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\WorkspaceMemberRole;
use App\Tests\Support\TenantFixtureTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end write path of the service catalogue: create a Service, release a
 * priced ServiceVersion through the release endpoint, assign a customer to it,
 * and prove the effective price resolves the version price vs. a per-assignment
 * override. Same one-kernel + rolled-back-transaction isolation as the other
 * functional suites.
 */
final class ServiceCatalogTest extends WebTestCase
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

    public function testReleaseVersionThenAssignWithInheritedThenOverriddenPrice(): void
    {
        $ws = $this->makeWorkspace('svc');
        $user = $this->makeUser('svc.admin@example.test', ['ROLE_USER']);
        $this->makeMember($user, $ws, WorkspaceMemberRole::Admin);
        $customer = $this->makeCustomer($ws, 'Katalog Kunde');
        $this->em->flush();

        $token = $this->jwt($user);
        $wsId = $ws->getId()?->toRfc4122();
        $customerIri = '/v1/customers/' . $customer->getId()?->toRfc4122();

        // 1) Create the catalogue service.
        $this->apiRequest('POST', '/v1/services', $token, ['CONTENT_TYPE' => 'application/ld+json'], json_encode([
            'name' => 'TYPO3 Monitoring Service',
            'workspace' => '/v1/workspaces/' . $wsId,
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(201, $this->responseStatus(), $this->rawBody());
        $serviceId = $this->jsonBody()['id'];

        // 2) Release a priced version (canonical create path — no POST on the resource).
        $this->apiRequest('POST', '/v1/services/' . $serviceId . '/versions', $token, ['CONTENT_TYPE' => 'application/json'], json_encode([
            'netPriceCents' => 12000,
            'currency' => 'eur',
            'billingCycle' => 'yearly',
            'label' => 'v1',
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(201, $this->responseStatus(), $this->rawBody());
        $release = $this->jsonBody();
        self::assertSame(1, $release['versionNo']);
        self::assertTrue($release['isCurrent']);
        $versionIri = '/v1/service_versions/' . $release['id'];

        // 3) Assign the customer — no override → inherits the version's net price.
        $this->apiRequest('POST', '/v1/service_assignments', $token, ['CONTENT_TYPE' => 'application/ld+json'], json_encode([
            'customer' => $customerIri,
            'serviceVersion' => $versionIri,
            'startedOn' => '2026-01-01',
            'status' => 'active',
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(201, $this->responseStatus(), $this->rawBody());
        $assignment = $this->jsonBody();
        $assignmentId = $assignment['id'];
        self::assertNull($assignment['netPriceOverrideCents']);
        self::assertSame(12000, $assignment['effectivePriceCents']);

        // 4) Override the price for this customer → effective price follows the override.
        $this->apiPatch('/v1/service_assignments/' . $assignmentId, $token, ['netPriceOverrideCents' => 9900]);
        self::assertSame(200, $this->responseStatus(), $this->rawBody());
        self::assertSame(9900, $this->jsonBody()['effectivePriceCents']);
    }
}
