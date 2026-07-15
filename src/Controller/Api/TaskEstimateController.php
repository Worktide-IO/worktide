<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Task;
use App\Message\EstimateTaskMessage;
use App\Security\Voter\WorktidePermission;
use App\Service\Ai\EffortEstimationAssistant;
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
 * On-demand AI effort estimation for a task (human-in-the-loop):
 *
 *   POST /v1/tasks/{id}/ai-estimate
 *
 * Queues an {@see EstimateTaskMessage} on the `ai_agents` transport and returns
 * 202; the worker produces a Pending {@see \App\Entity\AIRecommendation} of kind
 * `estimate` and pushes it over Mercure. Fails fast (409) when the LLM is
 * unconfigured or LLM egress isn't approved. Mirrors {@see TicketTriageController}.
 */
final class TaskEstimateController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly EffortEstimationAssistant $assistant,
        private readonly EgressGuard $egress,
    ) {}

    #[Route(
        path: '/v1/tasks/{id}/ai-estimate',
        name: 'api_task_ai_estimate',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function __invoke(string $id): JsonResponse
    {
        $task = $this->em->find(Task::class, Uuid::fromString($id));
        if (!$task instanceof Task) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $task)) {
            throw new AccessDeniedHttpException();
        }
        $taskId = $task->getId();
        if ($taskId === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->assistant->isAvailable()) {
            throw new ConflictHttpException('AI is not configured (missing ANTHROPIC_API_KEY).');
        }
        if (!$this->egress->isAllowed(EgressModule::Llm)) {
            throw new ConflictHttpException('LLM egress is not approved (add "llm" to EGRESS_ALLOW).');
        }

        $this->bus->dispatch(new EstimateTaskMessage($taskId));

        return new JsonResponse(['status' => 'queued'], Response::HTTP_ACCEPTED);
    }
}
