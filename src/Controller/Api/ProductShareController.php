<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\ProductShareStatus;
use App\Entity\Product;
use App\Entity\ProductShare;
use App\Entity\Workspace;
use App\Service\Catalog\ProductShareService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

#[Route('/v1')]
#[IsGranted('ROLE_USER')]
final class ProductShareController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductShareService $shareService,
    ) {}

    #[Route('/products/{id}/share', methods: ['POST'], name: 'api_product_share_propose')]
    public function propose(string $id, Request $request): JsonResponse
    {
        $product = $this->em->find(Product::class, $id);
        if (!$product) {
            throw $this->createNotFoundException('Product not found.');
        }
        $this->denyAccessUnlessGranted('EDIT', $product->getWorkspace());

        $data = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $targetWorkspaceId = $data['targetWorkspace'] ?? null;
        $message = $data['message'] ?? null;

        if (!\is_string($targetWorkspaceId)) {
            throw new BadRequestHttpException('targetWorkspace is required.');
        }

        $targetWorkspace = $this->em->find(Workspace::class, $targetWorkspaceId);
        if (!$targetWorkspace) {
            throw $this->createNotFoundException('Target workspace not found.');
        }

        if ($targetWorkspace->getId()->equals($product->getWorkspace()->getId())) {
            throw new BadRequestHttpException('Cannot share a product with your own workspace.');
        }

        $existing = $this->em->getRepository(ProductShare::class)->findOneBy([
            'product' => $product,
            'sourceWorkspace' => $product->getWorkspace(),
            'targetWorkspace' => $targetWorkspace,
        ]);
        if ($existing) {
            throw new BadRequestHttpException('This product has already been shared with this workspace.');
        }

        $share = (new ProductShare())
            ->setSourceWorkspace($product->getWorkspace())
            ->setTargetWorkspace($targetWorkspace)
            ->setProduct($product)
            ->setStatus(ProductShareStatus::Proposed)
            ->setMessage(\is_string($message) ? $message : null);

        $this->em->persist($share);
        $this->em->flush();

        return $this->json([
            '@id' => '/v1/product_shares/' . $share->getId()?->toRfc4122(),
            'status' => $share->getStatus()->value,
        ], Response::HTTP_CREATED);
    }

    #[Route('/product_shares/{id}/accept', methods: ['POST'], name: 'api_product_share_accept')]
    public function accept(string $id): JsonResponse
    {
        $share = $this->em->find(ProductShare::class, $id);
        if (!$share) {
            throw $this->createNotFoundException('Share proposal not found.');
        }
        $this->denyAccessUnlessGranted('EDIT', $share->getTargetWorkspace());

        if ($share->getStatus() !== ProductShareStatus::Proposed) {
            throw new BadRequestHttpException('Only proposed shares can be accepted.');
        }

        $copy = $this->shareService->accept($share);

        return $this->json([
            '@id' => '/v1/product_shares/' . $share->getId()?->toRfc4122(),
            'status' => $share->getStatus()->value,
            'sharedCopy' => $copy->getId()?->toRfc4122(),
        ]);
    }

    #[Route('/product_shares/{id}/reject', methods: ['POST'], name: 'api_product_share_reject')]
    public function reject(string $id): JsonResponse
    {
        $share = $this->em->find(ProductShare::class, $id);
        if (!$share) {
            throw $this->createNotFoundException('Share proposal not found.');
        }
        $this->denyAccessUnlessGranted('EDIT', $share->getTargetWorkspace());

        if ($share->getStatus() !== ProductShareStatus::Proposed) {
            throw new BadRequestHttpException('Only proposed shares can be rejected.');
        }

        $share->setStatus(ProductShareStatus::Rejected);
        $this->em->flush();

        return $this->json(['status' => $share->getStatus()->value]);
    }
}
