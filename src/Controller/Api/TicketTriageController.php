<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Conversation;
use App\Entity\Enum\RecommendationTarget;
use App\Entity\Task;
use App\Message\TriageTicketMessage;
use App\Security\Voter\WorktidePermission;
use App\Service\Ai\TicketTriageAssistant;
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
 * On-demand AI triage trigger for a ticket (human-in-the-loop):
 *
 *   POST /v1/tasks/{id}/ai-triage
 *   POST /v1/conversations/{id}/ai-triage
 *
 * Queues a {@see TriageTicketMessage} on the `ai_agents` transport and returns
 * 202; the worker produces a Pending {@see \App\Entity\AIRecommendation} and
 * pushes it over Mercure. Fails fast (409) when the LLM is unconfigured or LLM
 * egress isn't approved, rather than silently queueing work that can't run.
 */
final class TicketTriageController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly TicketTriageAssistant $assistant,
        private readonly EgressGuard $egress,
    ) {}

    #[Route(
        path: '/v1/tasks/{id}/ai-triage',
        name: 'api_task_ai_triage',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function triageTask(string $id): JsonResponse
    {
        $task = $this->em->find(Task::class, Uuid::fromString($id));
        if (!$task instanceof Task) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $task)) {
            throw new AccessDeniedHttpException();
        }

        return $this->dispatch(RecommendationTarget::Task, $task->getId());
    }

    #[Route(
        path: '/v1/conversations/{id}/ai-triage',
        name: 'api_conversation_ai_triage',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function triageConversation(string $id): JsonResponse
    {
        $conversation = $this->em->find(Conversation::class, Uuid::fromString($id));
        if (!$conversation instanceof Conversation) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $conversation->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }

        return $this->dispatch(RecommendationTarget::Conversation, $conversation->getId());
    }

    private function dispatch(RecommendationTarget $target, ?Uuid $targetId): JsonResponse
    {
        if ($targetId === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->assistant->isAvailable()) {
            throw new ConflictHttpException('AI is not configured (missing ANTHROPIC_API_KEY).');
        }
        if (!$this->egress->isAllowed(EgressModule::Llm)) {
            throw new ConflictHttpException('LLM egress is not approved (add "llm" to EGRESS_ALLOW).');
        }

        $this->bus->dispatch(new TriageTicketMessage($target, $targetId));

        return new JsonResponse(['status' => 'queued'], Response::HTTP_ACCEPTED);
    }
}
