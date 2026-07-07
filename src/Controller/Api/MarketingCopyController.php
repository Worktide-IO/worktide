<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Product;
use App\Message\GenerateMarketingCopyMessage;
use App\Security\Voter\WorktidePermission;
use App\Service\Ai\MarketingCopyAssistant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * On-demand AI marketing-draft trigger for a catalog product/service
 * (human-in-the-loop):
 *
 *   POST /v1/products/{id}/ai-marketing-draft
 *
 * Queues a {@see GenerateMarketingCopyMessage} on the `ai_agents` transport and
 * returns 202; the worker produces a Pending {@see \App\Entity\AIRecommendation}
 * ({@see \App\Entity\Enum\RecommendationKind::MarketingSocialDraft}) and pushes it
 * over Mercure. Fails fast (409) when the LLM is unconfigured or LLM egress isn't
 * approved, rather than silently queueing work that can't run. Mirrors
 * {@see TicketTriageController}.
 */
final class MarketingCopyController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly MarketingCopyAssistant $assistant,
        private readonly EgressGuard $egress,
    ) {}

    #[Route(
        path: '/v1/products/{id}/ai-marketing-draft',
        name: 'api_product_ai_marketing_draft',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function draft(string $id): JsonResponse
    {
        $product = $this->em->find(Product::class, Uuid::fromString($id));
        if (!$product instanceof Product) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $product->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }

        $productId = $product->getId();
        if ($productId === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->assistant->isAvailable()) {
            throw new ConflictHttpException('AI is not configured (missing LLM credentials).');
        }
        if (!$this->egress->isAllowed(EgressModule::Llm)) {
            throw new ConflictHttpException('LLM egress is not approved (add "llm" to EGRESS_ALLOW).');
        }

        $this->bus->dispatch(new GenerateMarketingCopyMessage($productId));

        return new JsonResponse(['status' => 'queued'], Response::HTTP_ACCEPTED);
    }
}
