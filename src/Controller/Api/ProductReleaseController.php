<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Product;
use App\Entity\User;
use App\Service\Catalog\ProductCatalogService;
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
 * Ship a new version of a product — the canonical creation path for
 * {@see \App\Entity\ProductVersion}, so latest-version bookkeeping stays
 * consistent (see {@see ProductCatalogService}).
 *
 *   POST /v1/products/{id}/release
 *   body: { version, releaseDate?, releaseNotes? }
 */
final class ProductReleaseController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ProductCatalogService $catalog,
    ) {}

    #[Route(
        path: '/v1/products/{id}/release',
        name: 'api_product_release',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        if (!$this->security->getUser() instanceof User) {
            throw new AccessDeniedHttpException();
        }
        $product = $this->em->find(Product::class, Uuid::fromString($id));
        if (!$product instanceof Product) {
            throw new NotFoundHttpException('Product not found.');
        }
        if (!$this->security->isGranted('EDIT', $product->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            throw new BadRequestHttpException('Expected a JSON object body.');
        }
        $version = isset($data['version']) && \is_string($data['version']) ? $data['version'] : '';

        $releaseDate = null;
        if (!empty($data['releaseDate']) && \is_string($data['releaseDate'])) {
            try {
                $releaseDate = new \DateTimeImmutable($data['releaseDate']);
            } catch (\Exception) {
                throw new BadRequestHttpException('"releaseDate" is not a valid date.');
            }
        }
        $releaseNotes = isset($data['releaseNotes']) && \is_string($data['releaseNotes']) ? $data['releaseNotes'] : null;

        $actor = $this->security->getUser();

        try {
            $pv = $this->catalog->release(
                $product,
                $version,
                $releaseDate,
                $releaseNotes,
                $actor instanceof User ? $actor : null,
            );
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        return new JsonResponse([
            'id' => $pv->getId()?->toRfc4122(),
            'productId' => $product->getId()?->toRfc4122(),
            'version' => $pv->getVersion(),
            'status' => $pv->getStatus()->value,
            'isLatest' => $pv->isLatest(),
            'releaseDate' => $pv->getReleaseDate()?->format('Y-m-d'),
        ], JsonResponse::HTTP_CREATED);
    }
}
