<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\SocialPost;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * AI text suggestions per network (Phase S4):
 *
 *   POST /v1/social_posts/{id}/ai-suggest { "network": "...", "tone"?: "..." }
 *
 * Requires EDIT. Returns AI-drafted text variants the user can paste/edit —
 * never auto-applied or auto-published (human-in-the-loop).
 *
 * Placeholder until the LLM provider (LlmProviderInterface + AnthropicClient)
 * is wired in S4; responds 501 so the endpoint exists and the SPA can detect
 * availability.
 */
final class SocialPostAiSuggestController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/social_posts/{id}/ai-suggest',
        name: 'api_social_post_ai_suggest',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function __invoke(string $id): JsonResponse
    {
        $post = $this->em->find(SocialPost::class, Uuid::fromString($id));
        if ($post === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $post)) {
            throw new AccessDeniedHttpException();
        }

        return new JsonResponse(
            ['message' => 'AI suggestions are not enabled yet (Phase S4).'],
            Response::HTTP_NOT_IMPLEMENTED,
        );
    }
}
