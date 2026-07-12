<?php

declare(strict_types=1);

namespace App\Tests\Functional\Portal;

use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Workspace;
use App\Tests\Support\TenantFixtureTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase-T portal isolation: two customers in the SAME workspace must never see
 * each other's data through the `/v1/portal/*` endpoints. Every portal endpoint
 * funnels through the one choke-point {@see \App\Service\Portal\PortalAccessResolver}
 * (`contact()` → `customer()`), so proving isolation on the invoices endpoint —
 * which scopes purely by `PortalAccessResolver::customer()` — exercises the seam
 * that tickets/agreements/documents/etc. all share.
 *
 * Complements the existing per-endpoint portal tests (files:
 * {@see PortalFilesIsolationTest}; newsletter consent: PortalNewsletterConsentTest)
 * — the same customer boundary, a different, financially-sensitive surface.
 */
final class PortalCrossCustomerIsolationTest extends WebTestCase
{
    use TenantFixtureTrait;

    private const HOST = 'api.worktide.ddev.site';

    protected function setUp(): void
    {
        $this->bootTenant();
    }

    protected function tearDown(): void
    {
        $this->rollbackTenant();
        parent::tearDown();
    }

    public function testContactSeesOnlyOwnCustomerInvoices(): void
    {
        $ctx = $this->seed();

        // Contact X sees only X's invoice number; Y's is absent.
        $this->portalGet('/v1/portal/invoices', $ctx['tokenX']);
        self::assertSame(200, $this->responseStatus());
        $numbers = array_column($this->jsonBody()['invoices'], 'number');
        self::assertContains('INV-X-001', $numbers);
        self::assertNotContains('INV-Y-001', $numbers);

        // …and symmetrically: Y sees only Y's.
        $this->portalGet('/v1/portal/invoices', $ctx['tokenY']);
        self::assertSame(200, $this->responseStatus());
        $numbers = array_column($this->jsonBody()['invoices'], 'number');
        self::assertContains('INV-Y-001', $numbers);
        self::assertNotContains('INV-X-001', $numbers);
    }

    /**
     * @return array{tokenX: string, tokenY: string}
     */
    private function seed(): array
    {
        $ws = $this->makeWorkspace('portal-iso', [
            'portal' => ['enabled' => true, 'features' => ['invoices' => true]],
        ]);

        $customerX = $this->makeCustomer($ws, 'Kunde X', true);
        $customerY = $this->makeCustomer($ws, 'Kunde Y', true);

        $tokenX = $this->portalContact($customerX, 'x.contact@example.test');
        $tokenY = $this->portalContact($customerY, 'y.contact@example.test');

        $this->invoice($customerX, 'INV-X-001', 'lex-x-1');
        $this->invoice($customerY, 'INV-Y-001', 'lex-y-1');

        $this->em->flush();
        $this->em->clear();

        return ['tokenX' => $tokenX, 'tokenY' => $tokenY];
    }

    /** Create a portal contact linked to a fresh ROLE_PORTAL user; return that user's JWT. */
    private function portalContact(Customer $customer, string $email): string
    {
        $user = $this->makeUser($email, ['ROLE_PORTAL']);
        $this->em->persist(
            (new Contact())
                ->setCustomer($customer)
                ->setFirstName('P')
                ->setLastName('Contact')
                ->setEmail($email)
                ->setLinkedUser($user),
        );

        return $this->jwt($user);
    }

    private function invoice(Customer $customer, string $number, string $lexId): void
    {
        $invoice = (new Invoice())
            ->setCustomer($customer) // also sets workspace
            ->setLexofficeId($lexId)
            ->setNumber($number)
            ->setIssuedOn(new \DateTimeImmutable('2026-01-15'))
            ->setTotalCents(119_00);
        $this->em->persist($invoice);
    }

    private function portalGet(string $uri, string $token): void
    {
        $this->apiRequest('GET', $uri, $token, ['HTTP_HOST' => self::HOST]);
    }
}
