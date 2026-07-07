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
 * Cancel a post before it goes live:
 *
 *   POST /v1/social_posts/{id}/cancel
 *
 * Requires EDIT. Allowed from Draft / PendingApproval / Scheduled. A post that
 * is already Publishing or (Partially)Published cannot be canceled — those
 * targets are settled.
 */
final class SocialPostCancelController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/social_posts/{id}/cancel',
        name: 'api_social_post_cancel',
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
        $cancelable = [SocialPostStatus::Draft, SocialPostStatus::PendingApproval, SocialPostStatus::Scheduled];
        if (!\in_array($post->getStatus(), $cancelable, true)) {
            throw new ConflictHttpException(sprintf('Cannot cancel a post in "%s" state.', $post->getStatus()->value));
        }

        $post->setStatus(SocialPostStatus::Canceled);
        $this->em->flush();

        return new JsonResponse(['status' => $post->getStatus()->value]);
    }
}
