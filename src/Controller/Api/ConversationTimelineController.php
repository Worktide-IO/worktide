<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Security\Voter\WorktidePermission;
use App\Service\ConversationTimeline;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Unified thread view of a conversation — customer messages, agent replies,
 * forwards, and internal notes merged chronologically:
 *
 *   GET /v1/conversations/{id}/timeline
 *
 * Access gated on VIEW of the conversation's workspace. Assembly lives in
 * {@see ConversationTimeline}.
 */
final class ConversationTimelineController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly ConversationTimeline $timeline,
    ) {}

    #[Route(
        path: '/v1/conversations/{id}/timeline',
        name: 'api_conversation_timeline',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['GET'],
    )]
    public function __invoke(string $id): JsonResponse
    {
        $conversation = $this->em->find(Conversation::class, Uuid::fromString($id));
        if ($conversation === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $conversation->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }

        return new JsonResponse(['items' => $this->timeline->build($conversation)]);
    }
}
