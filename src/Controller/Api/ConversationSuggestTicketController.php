<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Conversation;
use App\Message\SuggestConversationTicketMessage;
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
 * On-demand AI ticket suggestion for a conversation (human-in-the-loop):
 *
 *   POST /v1/conversations/{id}/suggest-ticket
 *
 * Queues a {@see SuggestConversationTicketMessage} on the `ai_agents` transport
 * and returns 202; the worker asks the LLM whether the conversation warrants a
 * ticket and, if so, persists a Pending AIRecommendation. Works for any mailbox
 * (personal too) — the automatic path only fires for shared mailboxes.
 * Fails fast (409) when the LLM is unconfigured or LLM egress isn't approved.
 */
final class ConversationSuggestTicketController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly TicketTriageAssistant $assistant,
        private readonly EgressGuard $egress,
    ) {}

    #[Route(
        path: '/v1/conversations/{id}/suggest-ticket',
        name: 'api_conversation_suggest_ticket',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function __invoke(string $id): JsonResponse
    {
        $conversation = $this->em->find(Conversation::class, Uuid::fromString($id));
        if (!$conversation instanceof Conversation) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $conversation)) {
            throw new AccessDeniedHttpException();
        }
        if (!$this->assistant->isAvailable()) {
            throw new ConflictHttpException('AI is not configured (missing ANTHROPIC_API_KEY).');
        }
        if (!$this->egress->isAllowed(EgressModule::Llm)) {
            throw new ConflictHttpException('LLM egress is not approved (add "llm" to EGRESS_ALLOW).');
        }

        $this->bus->dispatch(new SuggestConversationTicketMessage($conversation->getId() ?? Uuid::fromString($id)));

        return new JsonResponse(['status' => 'queued'], Response::HTTP_ACCEPTED);
    }
}
