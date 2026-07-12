<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AgreementLineItem;
use App\Entity\Customer;
use App\Entity\CustomerAgreement;
use App\Entity\CustomerAgreementRevision;
use App\Entity\Enum\AgreementStatus;
use App\Entity\File;
use App\Entity\User;
use App\Repository\AgreementTypeRepository;
use App\Repository\CustomerAgreementRepository;
use App\Service\Crm\AgreementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Slug-keyed convenience facade over {@see CustomerAgreement} so clients can
 * read and set a customer's contract status by a simple key — without juggling
 * type IRIs or revision objects. The robust head/revision model and history
 * are maintained underneath by {@see AgreementService}.
 *
 *   GET  /v1/customers/{id}/agreements/{slug}   → current state (200 even if
 *                                                 none on file: status "none")
 *   PUT  /v1/customers/{id}/agreements/{slug}   → record a new version
 *        body: { status, signedOn?, validUntil?, reference?, fileId?, notes? }
 */
final class CustomerAgreementController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly AgreementService $agreements,
        private readonly AgreementTypeRepository $types,
        private readonly CustomerAgreementRepository $heads,
    ) {}

    #[Route(
        path: '/v1/customers/{id}/agreements/{slug}',
        name: 'api_customer_agreement_get',
        requirements: ['id' => Requirement::UUID_V7, 'slug' => '[a-z0-9][a-z0-9_-]*'],
        methods: ['GET'],
    )]
    public function get(string $id, string $slug): JsonResponse
    {
        $customer = $this->loadCustomer($id);
        if (!$this->security->isGranted('VIEW', $customer->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }

        $type = $this->types->findOneBySlug($customer->getWorkspace(), $slug);
        if ($type === null) {
            throw new NotFoundHttpException(\sprintf('Unknown agreement type "%s".', $slug));
        }

        $head = $this->heads->findOneForType($customer, $type);
        if ($head === null) {
            // Type exists but nothing recorded yet — answer with an explicit "none".
            return new JsonResponse([
                'customerId' => $id,
                'typeSlug' => $type->getSlug(),
                'status' => AgreementStatus::None->value,
                'isSigned' => false,
                'signedOn' => null,
                'validUntil' => null,
                'currentVersion' => null,
                'pendingVersion' => null,
                'agreementId' => null,
            ]);
        }

        return new JsonResponse($this->serialize($head));
    }

    #[Route(
        path: '/v1/customers/{id}/agreements/{slug}',
        name: 'api_customer_agreement_put',
        requirements: ['id' => Requirement::UUID_V7, 'slug' => '[a-z0-9][a-z0-9_-]*'],
        methods: ['PUT'],
    )]
    public function put(string $id, string $slug, Request $request): JsonResponse
    {
        $customer = $this->loadCustomer($id);
        if (!$this->security->isGranted('EDIT', $customer->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            throw new BadRequestHttpException('Expected a JSON object body.');
        }

        $status = AgreementStatus::tryFrom((string) ($data['status'] ?? ''));
        if ($status === null) {
            throw new BadRequestHttpException('Field "status" must be one of: '
                . implode(', ', array_map(static fn (AgreementStatus $s) => $s->value, AgreementStatus::cases())));
        }

        $signedOn = $this->parseDate($data['signedOn'] ?? null, 'signedOn');
        $validUntil = $this->parseDate($data['validUntil'] ?? null, 'validUntil');
        $reference = isset($data['reference']) && \is_string($data['reference']) ? $data['reference'] : null;
        $notes = isset($data['notes']) && \is_string($data['notes']) ? $data['notes'] : null;

        $file = null;
        if (!empty($data['fileId'])) {
            try {
                $file = $this->em->find(File::class, Uuid::fromString((string) $data['fileId']));
            } catch (\InvalidArgumentException) {
                throw new BadRequestHttpException('"fileId" is not a valid UUID.');
            }
            if ($file === null || $file->getWorkspace() !== $customer->getWorkspace()) {
                throw new BadRequestHttpException('Referenced file not found in this workspace.');
            }
        }

        $actor = $this->security->getUser();

        try {
            $head = $this->agreements->set(
                $customer,
                $slug,
                $status,
                $signedOn,
                $validUntil,
                $reference,
                $file,
                $notes,
                $actor instanceof User ? $actor : null,
            );
        } catch (\InvalidArgumentException $e) {
            throw new NotFoundHttpException($e->getMessage());
        }

        return new JsonResponse($this->serialize($head));
    }

    /**
     * Replace the line items of the agreement's in-force (current ?? pending)
     * revision, in place — no new version, so the sign/negotiation flow is
     * untouched. Each item carries optional per-locale `translations` of its
     * description (content i18n).
     */
    #[Route(
        path: '/v1/customers/{id}/agreements/{slug}/line-items',
        name: 'api_customer_agreement_line_items_put',
        requirements: ['id' => Requirement::UUID_V7, 'slug' => '[a-z0-9][a-z0-9_-]*'],
        methods: ['PUT'],
    )]
    public function putLineItems(string $id, string $slug, Request $request): JsonResponse
    {
        $customer = $this->loadCustomer($id);
        if (!$this->security->isGranted('EDIT', $customer->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }

        $type = $this->types->findOneBySlug($customer->getWorkspace(), $slug);
        if ($type === null) {
            throw new NotFoundHttpException(\sprintf('Unknown agreement type "%s".', $slug));
        }
        $head = $this->heads->findOneForType($customer, $type);
        $revision = $head?->getCurrentRevision() ?? $head?->getPendingRevision();
        if ($head === null || $revision === null) {
            throw new ConflictHttpException('Record the agreement before adding line items.');
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !\is_array($data['lineItems'] ?? null)) {
            throw new BadRequestHttpException('Expected { "lineItems": [...] }.');
        }

        // Replace the revision's line items with the posted set (orphanRemoval
        // deletes the dropped ones on flush).
        foreach ($revision->getLineItems()->toArray() as $existing) {
            $revision->getLineItems()->removeElement($existing);
            $this->em->remove($existing);
        }
        foreach (array_values($data['lineItems']) as $i => $raw) {
            if (!\is_array($raw)) {
                continue;
            }
            $li = (new AgreementLineItem())
                ->setRevision($revision)
                ->setDescription((string) ($raw['description'] ?? ''))
                ->setQuantity((float) ($raw['quantity'] ?? 1))
                ->setUnitAmountCents((int) ($raw['unitAmountCents'] ?? 0))
                ->setCurrency((string) ($raw['currency'] ?? 'EUR'))
                ->setIsRecurring((bool) ($raw['isRecurring'] ?? false))
                ->setPosition($i);
            if (\is_array($raw['translations'] ?? null)) {
                /** @var array<string, array<string, string>> $tr */
                $tr = $raw['translations'];
                $li->setTranslations($tr);
            }
            $revision->getLineItems()->add($li);
            $this->em->persist($li);
        }
        $this->em->flush();

        return new JsonResponse($this->serialize($head));
    }

    private function loadCustomer(string $id): Customer
    {
        // Authenticate before touching the lookup so anonymous callers can't
        // probe customer existence (404) vs. permission (403) on this route.
        if (!$this->security->getUser() instanceof User) {
            throw new AccessDeniedHttpException();
        }
        $customer = $this->em->find(Customer::class, Uuid::fromString($id));
        if (!$customer instanceof Customer) {
            throw new NotFoundHttpException('Customer not found.');
        }

        return $customer;
    }

    private function parseDate(mixed $value, string $field): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!\is_string($value)) {
            throw new BadRequestHttpException(\sprintf('"%s" must be an ISO date string.', $field));
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BadRequestHttpException(\sprintf('"%s" is not a valid date.', $field));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CustomerAgreement $head): array
    {
        $rev = $head->getCurrentRevision() ?? $head->getPendingRevision();

        return [
            'customerId' => $head->getCustomer()->getId()?->toRfc4122(),
            'agreementId' => $head->getId()?->toRfc4122(),
            'typeSlug' => $head->getTypeSlug(),
            'status' => $head->getStatus()->value,
            'isSigned' => $head->getIsSigned(),
            'signedOn' => $head->getSignedOn()?->format('Y-m-d'),
            'validUntil' => $head->getValidUntil()?->format('Y-m-d'),
            'currentVersion' => $head->getCurrentRevision()?->getVersionNo(),
            'pendingVersion' => $head->getPendingRevision()?->getVersionNo(),
            // The in-force revision's priced lines, with per-locale description
            // overrides — the staff line-item editor loads these.
            'revisionId' => $rev?->getId()?->toRfc4122(),
            'lineItems' => $rev === null ? [] : array_map(
                static fn (AgreementLineItem $li): array => [
                    'id' => $li->getId()?->toRfc4122(),
                    'description' => $li->getDescription(),
                    'quantity' => $li->getQuantity(),
                    'unitAmountCents' => $li->getUnitAmountCents(),
                    'currency' => $li->getCurrency(),
                    'isRecurring' => $li->isRecurring(),
                    'translations' => $li->getTranslations(),
                ],
                $rev->getLineItems()->toArray(),
            ),
        ];
    }
}
