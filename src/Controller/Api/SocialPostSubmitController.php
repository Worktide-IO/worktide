<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\SocialPostStatus;
use App\Entity\SocialPost;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Submit a draft for approval:
 *
 *   POST /v1/social_posts/{id}/submit
 *
 * Requires EDIT. Moves Draft → PendingApproval. The approval itself is a
 * separate MANAGE-gated action ({@see SocialPostApproveController}).
 */
final class SocialPostSubmitController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/social_posts/{id}/submit',
        name: 'api_social_post_submit',
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
        if ($post->getStatus() !== SocialPostStatus::Draft) {
            throw new ConflictHttpException(sprintf('Only a draft can be submitted (post is "%s").', $post->getStatus()->value));
        }

        $post->setStatus(SocialPostStatus::PendingApproval);
        $this->em->flush();

        return new JsonResponse(['status' => $post->getStatus()->value]);
    }
}
