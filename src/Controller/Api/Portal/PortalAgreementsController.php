<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\CustomerAgreement;
use App\Entity\CustomerAgreementRevision;
use App\Entity\ServiceSubscription;
use App\Repository\CustomerAgreementRepository;
use App\Repository\ServiceSubscriptionRepository;
use App\Service\Portal\PortalAccessResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Customer-portal "Angebote & Verträge" screen (wireframe screen 4).
 *
 * Read-only view of the customer's agreements (offers + contracts, by status,
 * with the offer/contract reference and whether a signed document exists) and
 * their recurring service subscriptions (hosting/maintenance/SLA, with price
 * and billing cadence).
 *
 * SCOPE: structured line-items, the digital-signing flow, and invoices are NOT
 * modelled as portal data — line-items live inside the agreement PDF, signing
 * is a legal/audit write-flow, and there is no Invoice entity. Those are
 * deferred; this ships what's truthfully backed. Gated by `agreements`.
 */
final class PortalAgreementsController
{
    private const AGREEMENT_STATUS_LABELS = [
        'draft' => 'Entwurf',
        'in_negotiation' => 'In Verhandlung',
        'signed' => 'Signiert',
        'expired' => 'Abgelaufen',
        'superseded' => 'Ersetzt',
        'terminated' => 'Gekündigt',
    ];

    private const SUBSCRIPTION_STATUS_LABELS = [
        'trial' => 'Test',
        'active' => 'Aktiv',
        'paused' => 'Pausiert',
        'cancelled' => 'Beendet',
    ];

    private const BILLING_LABELS = [
        'monthly' => 'monatlich',
        'quarterly' => 'vierteljährlich',
        'half_yearly' => 'halbjährlich',
        'yearly' => 'jährlich',
        'once' => 'einmalig',
    ];

    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly CustomerAgreementRepository $agreements,
        private readonly ServiceSubscriptionRepository $subscriptions,
    ) {}

    #[Route(
        path: '/v1/portal/agreements',
        name: 'api_portal_agreements_list',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function list(): JsonResponse
    {
        $this->portal->assertFeatureEnabled('agreements');

        $customer = $this->portal->customer();

        return new JsonResponse([
            'agreements' => array_map(
                $this->agreementDto(...),
                $this->agreements->findForPortalCustomer($customer),
            ),
            'subscriptions' => array_map(
                $this->subscriptionDto(...),
                $this->subscriptions->findForPortalCustomer($customer),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function agreementDto(CustomerAgreement $agreement): array
    {
        // The offer/contract reference + document live on the in-force revision,
        // or the pending one while still in negotiation.
        $revision = $agreement->getCurrentRevision() ?? $agreement->getPendingRevision();
        $status = $agreement->getStatus()->value;

        return [
            'id' => $agreement->getId()?->toRfc4122(),
            'type' => $agreement->getType()->getName(),
            'typeSlug' => $agreement->getTypeSlug(),
            'status' => $status,
            'statusLabel' => self::AGREEMENT_STATUS_LABELS[$status] ?? $status,
            'isSigned' => $agreement->getIsSigned(),
            'reference' => $revision?->getReference(),
            'signedOn' => $agreement->getSignedOn()?->format('Y-m-d'),
            'validUntil' => $agreement->getValidUntil()?->format('Y-m-d'),
            'hasDocument' => $revision instanceof CustomerAgreementRevision && $revision->getFile() !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionDto(ServiceSubscription $subscription): array
    {
        $status = $subscription->getStatus()->value;
        $cycle = $subscription->getBillingCycle()->value;

        return [
            'id' => $subscription->getId()?->toRfc4122(),
            'name' => $subscription->getName(),
            'description' => $subscription->getDescription(),
            'priceCents' => $subscription->getPriceCents(),
            'currency' => $subscription->getCurrency(),
            'billingCycle' => $cycle,
            'billingLabel' => self::BILLING_LABELS[$cycle] ?? $cycle,
            'status' => $status,
            'statusLabel' => self::SUBSCRIPTION_STATUS_LABELS[$status] ?? $status,
            'nextBillingOn' => $subscription->getNextBillingOn()?->format('Y-m-d'),
            'systemName' => $subscription->getSystem()?->getName(),
        ];
    }
}
