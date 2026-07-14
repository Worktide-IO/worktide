<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\BillingCycle;
use App\Entity\Service;
use App\Entity\User;
use App\Service\Catalog\ServiceCatalogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Release a new priced version of a service — the canonical creation path for
 * {@see \App\Entity\ServiceVersion}, so current-version bookkeeping stays
 * consistent (see {@see ServiceCatalogService}).
 *
 *   POST /v1/services/{id}/versions
 *   body: { netPriceCents, currency?, billingCycle, label?, changelog?, effectiveFrom? }
 */
final class ServiceVersionReleaseController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ServiceCatalogService $catalog,
    ) {}

    #[Route(
        path: '/v1/services/{id}/versions',
        name: 'api_service_version_release',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        if (!$this->security->getUser() instanceof User) {
            throw new AccessDeniedHttpException();
        }
        $service = $this->em->find(Service::class, Uuid::fromString($id));
        if (!$service instanceof Service) {
            throw new NotFoundHttpException('Service not found.');
        }
        if (!$this->security->isGranted('EDIT', $service->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            throw new BadRequestHttpException('Expected a JSON object body.');
        }

        if (!isset($data['netPriceCents']) || !\is_int($data['netPriceCents'])) {
            throw new BadRequestHttpException('"netPriceCents" (integer) is required.');
        }
        $billingCycle = BillingCycle::tryFrom((string) ($data['billingCycle'] ?? ''));
        if ($billingCycle === null) {
            throw new BadRequestHttpException('"billingCycle" must be one of: monthly, quarterly, half_yearly, yearly, once.');
        }
        $currency = isset($data['currency']) && \is_string($data['currency']) && $data['currency'] !== '' ? $data['currency'] : 'eur';
        $label = isset($data['label']) && \is_string($data['label']) ? $data['label'] : null;
        $changelog = isset($data['changelog']) && \is_string($data['changelog']) ? $data['changelog'] : null;

        $effectiveFrom = null;
        if (!empty($data['effectiveFrom']) && \is_string($data['effectiveFrom'])) {
            try {
                $effectiveFrom = new \DateTimeImmutable($data['effectiveFrom']);
            } catch (\Exception) {
                throw new BadRequestHttpException('"effectiveFrom" is not a valid date.');
            }
        }

        $actor = $this->security->getUser();

        try {
            $version = $this->catalog->releaseVersion(
                $service,
                $data['netPriceCents'],
                $currency,
                $billingCycle,
                $label,
                $changelog,
                $effectiveFrom,
                $actor instanceof User ? $actor : null,
            );
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        return new JsonResponse([
            'id' => $version->getId()?->toRfc4122(),
            'serviceId' => $service->getId()?->toRfc4122(),
            'versionNo' => $version->getVersionNo(),
            'netPriceCents' => $version->getNetPriceCents(),
            'currency' => $version->getCurrency(),
            'billingCycle' => $version->getBillingCycle()->value,
            'isCurrent' => $version->isCurrent(),
        ], JsonResponse::HTTP_CREATED);
    }
}
