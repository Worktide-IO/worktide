<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Entity\SavedReply;
use App\Entity\User;
use App\Security\Voter\WorktidePermission;
use App\Service\SavedReplyRenderer;
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
 * Renders a SavedReply against a conversation + the current agent:
 *
 *   POST /v1/saved_replies/{id}/render   { "conversation": "<iri|uuid>" }  (optional)
 *
 * Returns `{ "body": "<interpolated>" }`. Access gated on VIEW of the reply's
 * workspace. Interpolation lives in {@see SavedReplyRenderer}.
 */
final class SavedReplyRenderController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly SavedReplyRenderer $renderer,
    ) {}

    #[Route(
        path: '/v1/saved_replies/{id}/render',
        name: 'api_saved_reply_render',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $reply = $this->em->find(SavedReply::class, Uuid::fromString($id));
        if ($reply === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $reply->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }

        $conversation = $this->resolveConversation($request);
        $agent = $this->security->getUser();

        return new JsonResponse([
            'body' => $this->renderer->render($reply, $conversation, $agent instanceof User ? $agent : null),
        ]);
    }

    private function resolveConversation(Request $request): ?Conversation
    {
        $data = json_decode($request->getContent(), true);
        $ref = \is_array($data) ? ($data['conversation'] ?? null) : null;
        if ($ref === null || $ref === '') {
            return null;
        }
        if (!\is_string($ref)) {
            throw new BadRequestHttpException('"conversation" must be a UUID or IRI.');
        }

        $candidate = str_contains($ref, '/') ? substr((string) strrchr($ref, '/'), 1) : $ref;
        if (!Uuid::isValid($candidate)) {
            throw new BadRequestHttpException('"conversation" must be a UUID or IRI.');
        }

        $conversation = $this->em->find(Conversation::class, Uuid::fromString($candidate));
        if ($conversation === null) {
            throw new NotFoundHttpException('Conversation not found.');
        }

        return $conversation;
    }
}
