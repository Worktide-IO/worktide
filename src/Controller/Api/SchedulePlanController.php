<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Concern\ResolvesWorkspaceMembership;
use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\User;
use App\Message\PlanScheduleMessage;
use App\Service\Ai\SchedulePlanningAssistant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Trigger the AI work planner for the current staff member (human-in-the-loop):
 *
 *   POST /v1/me/ai-plan   (X-Workspace-Id honoured)
 *
 * Queues a {@see PlanScheduleMessage} on `ai_agents` (202); the worker plans the
 * caller's open tickets across their free time for the next 14 days and writes
 * the time slots. Fails fast (409) when the LLM is unconfigured or LLM egress
 * isn't approved. Budget is enforced inside the provider (feature "schedule").
 */
final class SchedulePlanController
{
    use ResolvesWorkspaceMembership;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly SchedulePlanningAssistant $assistant,
        private readonly EgressGuard $egress,
    ) {}

    #[Route(path: '/v1/me/ai-plan', name: 'api_me_ai_plan', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }
        $workspace = $this->resolveWorkspace($request, $user);
        if ($workspace === null) {
            throw new AccessDeniedHttpException('No workspace context.');
        }
        if (!$this->assistant->isAvailable()) {
            throw new ConflictHttpException('AI is not configured (missing ANTHROPIC_API_KEY).');
        }
        if (!$this->egress->isAllowed(EgressModule::Llm)) {
            throw new ConflictHttpException('LLM egress is not approved (add "llm" to EGRESS_ALLOW).');
        }

        $uid = $user->getId();
        $wid = $workspace->getId();
        if ($uid === null || $wid === null) {
            throw new AccessDeniedHttpException();
        }

        $this->bus->dispatch(new PlanScheduleMessage($uid, $wid));

        return new JsonResponse(['status' => 'queued'], Response::HTTP_ACCEPTED);
    }
}
