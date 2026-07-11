<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\CustomerAgreement;
use App\Entity\CustomerAgreementRevision;
use App\Entity\Enum\AgreementStatus;
use App\Entity\ProjectOffer;
use App\Entity\ServiceSubscription;
use App\Repository\CustomerAgreementRepository;
use App\Repository\ProjectOfferRepository;
use App\Repository\ServiceSubscriptionRepository;
use App\Service\Crm\AgreementService;
use App\Service\Portal\PortalAccessResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Customer-portal "Angebote & Verträge" screen (wireframe screen 4).
 *
 * Read-only view of the customer's agreements (offers + contracts, by status,
 * with the offer/contract reference, priced line-items + total, and whether a
 * signed document exists) and their recurring service subscriptions
 * (hosting/maintenance/SLA, with price and billing cadence).
 *
 * SCOPE: invoices are still NOT modelled as portal data (no Invoice entity yet)
 * and are deferred. Line-items now come from {@see \App\Entity\AgreementLineItem}
 * on the in-force revision. Gated by `agreements`.
 */
final class PortalAgreementsController
{
    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly CustomerAgreementRepository $agreements,
        private readonly ServiceSubscriptionRepository $subscriptions,
        private readonly ProjectOfferRepository $offers,
        private readonly AgreementService $agreementService,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route(
        path: '/v1/portal/agreements/{id}/sign',
        name: 'api_portal_agreements_sign',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function sign(string $id, Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('agreements');
        $customer = $this->portal->customer();

        $head = $this->agreements->find(Uuid::fromString($id));
        if (
            !$head instanceof CustomerAgreement
            || $head->getDeletedAt() !== null
            || $head->getCustomer()->getId()?->toRfc4122() !== $customer->getId()?->toRfc4122()
        ) {
            throw new NotFoundHttpException('Agreement not found.');
        }
        if (!$this->isSignable($head)) {
            throw new ConflictHttpException('This agreement is not open for signing.');
        }

        $fullName = \is_string($this->body($request)['fullName'] ?? null) ? trim($this->body($request)['fullName']) : '';
        if ($fullName === '') {
            throw new BadRequestHttpException('fullName (signature) required.');
        }

        // Carry the offer's reference/validity onto the signed version.
        $offerRevision = $head->getPendingRevision() ?? $head->getCurrentRevision();
        $now = new \DateTimeImmutable();

        // Canonical transition: creates a Signed revision + recomputes the head.
        $this->agreementService->set(
            $customer,
            $head->getTypeSlug(),
            AgreementStatus::Signed,
            $now,
            $offerRevision?->getValidUntil(),
            $offerRevision?->getReference(),
            null,
            null,
            $this->portal->contact()->getLinkedUser(),
        );

        // Record the digital signature on the head.
        $head->setSignedByName($fullName)->setSignedByContact($this->portal->contact());
        $this->em->flush();

        return new JsonResponse($this->agreementDto($head));
    }

    #[Route(
        path: '/v1/portal/agreements/{id}/inquiry',
        name: 'api_portal_agreements_inquiry',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function inquiry(string $id, Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('agreements');
        $customer = $this->portal->customer();

        $head = $this->agreements->find(Uuid::fromString($id));
        if (
            !$head instanceof CustomerAgreement
            || $head->getDeletedAt() !== null
            || $head->getCustomer()->getId()?->toRfc4122() !== $customer->getId()?->toRfc4122()
        ) {
            throw new NotFoundHttpException('Agreement not found.');
        }
        if (!$this->isSignable($head)) {
            throw new ConflictHttpException('This agreement is not open for a query.');
        }

        $message = \is_string($this->body($request)['message'] ?? null) ? trim($this->body($request)['message']) : '';
        if ($message === '') {
            throw new BadRequestHttpException('message required.');
        }

        // A query records a note for the agency; the offer stays open (no status
        // change — the customer hasn't decided). Staff follow up out-of-band.
        $head->setCustomerInquiry($message)->setInquiredAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse($this->agreementDto($head));
    }

    private function isSignable(CustomerAgreement $head): bool
    {
        return !$head->getIsSigned()
            && \in_array($head->getStatus(), [AgreementStatus::None, AgreementStatus::Draft, AgreementStatus::InNegotiation], true)
            && ($head->getPendingRevision() ?? $head->getCurrentRevision()) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $raw = $request->getContent();
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Body must be valid JSON.');
        }
        return \is_array($decoded) ? $decoded : [];
    }

    #[Route(
        path: '/v1/portal/agreements',
        name: 'api_portal_agreements_list',
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
            // Offers generated from accepted proposals ("Ideen-Pitch → Angebot").
            'projectOffers' => array_map(
                $this->offerDto(...),
                $this->offers->findForPortalCustomer($customer),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function offerDto(ProjectOffer $offer): array
    {
        $status = $offer->getStatus()->value;

        return [
            'id' => $offer->getId()?->toRfc4122(),
            'reference' => $offer->getReference(),
            'title' => $offer->getTitle(),
            'amountCents' => $offer->getAmountCents(),
            'currency' => $offer->getCurrency(),
            'status' => $status,
            'statusLabel' => $this->translator->trans('label.offer_status.' . $status),
            'createdAt' => $offer->getCreatedAt()?->format('Y-m-d'),
        ];
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

        $lineItems = [];
        $totalCents = 0;
        $currency = 'EUR';
        $hasRecurring = false;
        $hasOneOff = false;
        if ($revision instanceof CustomerAgreementRevision) {
            foreach ($revision->getLineItems() as $item) {
                $amount = $item->getAmountCents();
                $totalCents += $amount;
                $currency = $item->getCurrency();
                $item->isRecurring() ? $hasRecurring = true : $hasOneOff = true;
                $lineItems[] = [
                    'description' => $item->getDescription(),
                    'quantity' => $item->getQuantity(),
                    'unitAmountCents' => $item->getUnitAmountCents(),
                    'amountCents' => $amount,
                    'isRecurring' => $item->isRecurring(),
                ];
            }
        }

        return [
            'id' => $agreement->getId()?->toRfc4122(),
            'type' => $agreement->getType()->getName(),
            'typeSlug' => $agreement->getTypeSlug(),
            'status' => $status,
            'statusLabel' => $this->translator->trans('label.agreement_status.' . $status),
            'isSigned' => $agreement->getIsSigned(),
            'reference' => $revision?->getReference(),
            'signedOn' => $agreement->getSignedOn()?->format('Y-m-d'),
            'validUntil' => $agreement->getValidUntil()?->format('Y-m-d'),
            'hasDocument' => $revision instanceof CustomerAgreementRevision && $revision->getFile() !== null,
            'canSign' => $this->isSignable($agreement),
            'signedBy' => $agreement->getSignedByName(),
            'inquiry' => $agreement->getCustomerInquiry(),
            'inquiredAt' => $agreement->getInquiredAt()?->format(\DateTimeInterface::ATOM),
            'lineItems' => $lineItems,
            'totalCents' => $totalCents,
            'currency' => $currency,
            // Every priced line is monthly → the total is a monthly sum (no mix).
            'totalIsRecurring' => $hasRecurring && !$hasOneOff,
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
            'billingLabel' => $this->translator->trans('label.billing.' . $cycle),
            'status' => $status,
            'statusLabel' => $this->translator->trans('label.subscription_status.' . $status),
            'nextBillingOn' => $subscription->getNextBillingOn()?->format('Y-m-d'),
            'systemName' => $subscription->getSystem()?->getName(),
        ];
    }
}
