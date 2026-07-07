<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Customer;
use App\Message\GenerateUpgradeOutreachMessage;
use App\Security\Voter\WorktidePermission;
use App\Service\Ai\UpgradeOutreachAssistant;
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
 * On-demand AI upgrade-outreach trigger for a customer (human-in-the-loop):
 *
 *   POST /v1/customers/{id}/ai-upgrade-outreach
 *
 * Queues a {@see GenerateUpgradeOutreachMessage} on the `ai_agents` transport and
 * returns 202; the worker produces a Pending {@see \App\Entity\AIRecommendation}
 * ({@see \App\Entity\Enum\RecommendationKind::CustomerUpgradeOutreach}) and pushes
 * it over Mercure. Fails fast (409) when the LLM is unconfigured or LLM egress
 * isn't approved. Mirrors {@see MarketingCopyController}.
 */
final class UpgradeOutreachController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly UpgradeOutreachAssistant $assistant,
        private readonly EgressGuard $egress,
    ) {}

    #[Route(
        path: '/v1/customers/{id}/ai-upgrade-outreach',
        name: 'api_customer_ai_upgrade_outreach',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function draft(string $id): JsonResponse
    {
        $customer = $this->em->find(Customer::class, Uuid::fromString($id));
        if (!$customer instanceof Customer) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $customer->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }

        $customerId = $customer->getId();
        if ($customerId === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->assistant->isAvailable()) {
            throw new ConflictHttpException('AI is not configured (missing LLM credentials).');
        }
        if (!$this->egress->isAllowed(EgressModule::Llm)) {
            throw new ConflictHttpException('LLM egress is not approved (add "llm" to EGRESS_ALLOW).');
        }

        $this->bus->dispatch(new GenerateUpgradeOutreachMessage($customerId));

        return new JsonResponse(['status' => 'queued'], Response::HTTP_ACCEPTED);
    }
}
