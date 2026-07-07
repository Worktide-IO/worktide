<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\SocialPost;
use App\Security\Voter\WorktidePermission;
use App\Service\Llm\LlmException;
use App\Service\Social\SocialPostAiAssistant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * AI text suggestions per network (Phase S4):
 *
 *   POST /v1/social_posts/{id}/ai-suggest { "network"?: "social_linkedin", "tone"?: "..." }
 *
 * Requires EDIT. Returns AI-drafted variants of the post body, tuned to each
 * network's voice and character limit. Suggestions are drafts — never applied
 * or published automatically (human-in-the-loop). With `network` set, suggests
 * for that one network; otherwise for every target network on the post.
 *
 * Responds 503 when no LLM credential is configured, 502 on a provider failure.
 */
final class SocialPostAiSuggestController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly SocialPostAiAssistant $assistant,
    ) {}

    #[Route(
        path: '/v1/social_posts/{id}/ai-suggest',
        name: 'api_social_post_ai_suggest',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $post = $this->em->find(SocialPost::class, Uuid::fromString($id));
        if ($post === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $post)) {
            throw new AccessDeniedHttpException();
        }
        if (!$this->assistant->isAvailable()) {
            throw new HttpException(Response::HTTP_SERVICE_UNAVAILABLE, 'AI suggestions are not configured (set ANTHROPIC_API_KEY).');
        }

        $data = json_decode($request->getContent(), true);
        $data = \is_array($data) ? $data : [];
        $network = isset($data['network']) && \is_string($data['network']) ? $data['network'] : null;
        $tone = isset($data['tone']) && \is_string($data['tone']) ? $data['tone'] : null;

        try {
            $suggestions = $network !== null
                ? [$this->assistant->suggestForAdapter($post, $network, $tone)]
                : $this->assistant->suggestForPost($post, $tone);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        } catch (LlmException $e) {
            throw new HttpException(Response::HTTP_BAD_GATEWAY, $e->getMessage());
        }

        if ($suggestions === []) {
            throw new BadRequestHttpException('Post has no social target networks to suggest for.');
        }

        return new JsonResponse(['suggestions' => $suggestions]);
    }
}
