<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Conversation;
use App\Security\Voter\WorktidePermission;
use App\Service\Ai\ReplySuggestionAssistant;
use App\Service\Llm\LlmException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * On-demand AI reply suggestion for a conversation (human-in-the-loop):
 *
 *   POST /v1/conversations/{id}/suggest-reply  →  { "reply": "...", "model": "..." }
 *
 * Runs the LLM synchronously and returns the drafted reply inline — like
 * {@see AiSuggestTagsController}, nothing is persisted or sent. The SPA drops the
 * text into the reply composer for the agent to edit and send through the normal
 * OutboundMessage flow. Requires EDIT on the conversation. 503 when the LLM is
 * unconfigured or LLM egress isn't approved; 502 on a provider failure.
 */
final class AiSuggestReplyController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly ReplySuggestionAssistant $assistant,
        private readonly EgressGuard $egress,
    ) {}

    #[Route(
        path: '/v1/conversations/{id}/suggest-reply',
        name: 'api_conversation_suggest_reply',
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
            throw new HttpException(Response::HTTP_SERVICE_UNAVAILABLE, 'AI suggestions are not configured (set ANTHROPIC_API_KEY).');
        }
        if (!$this->egress->isAllowed(EgressModule::Llm)) {
            throw new HttpException(Response::HTTP_SERVICE_UNAVAILABLE, 'LLM egress is not approved (add "llm" to EGRESS_ALLOW).');
        }

        try {
            $reply = $this->assistant->suggestReply($conversation);
        } catch (LlmException $e) {
            throw new HttpException(Response::HTTP_BAD_GATEWAY, 'The AI provider failed to produce a reply.', $e);
        }

        return new JsonResponse(['reply' => $reply, 'model' => $this->assistant->getModel()]);
    }
}
