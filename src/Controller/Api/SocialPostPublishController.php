<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\SocialPostStatus;
use App\Entity\SocialPost;
use App\Entity\User;
use App\Message\PublishSocialPostMessage;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Publish a post immediately, ignoring any schedule (human-in-the-loop gate):
 *
 *   POST /v1/social_posts/{id}/publish
 *
 * Requires workspace MANAGE. Sets the post to Publishing and dispatches the
 * fan-out now. Useful to push a draft live or to fire a scheduled post early.
 */
final class SocialPostPublishController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {}

    #[Route(
        path: '/v1/social_posts/{id}/publish',
        name: 'api_social_post_publish',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function __invoke(string $id): JsonResponse
    {
        $post = $this->em->find(SocialPost::class, Uuid::fromString($id));
        if ($post === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::MANAGE, $post)) {
            throw new AccessDeniedHttpException();
        }
        if (\in_array($post->getStatus(), [SocialPostStatus::Published, SocialPostStatus::Canceled], true)) {
            throw new ConflictHttpException(sprintf('Cannot publish a post in "%s" state.', $post->getStatus()->value));
        }
        if ($post->getTargets()->isEmpty()) {
            throw new ConflictHttpException('Post has no target networks.');
        }

        if ($post->getApprovedAt() === null) {
            $user = $this->security->getUser();
            $post->setApprovedByUser($user instanceof User ? $user : null);
            $post->setApprovedAt(new \DateTimeImmutable());
        }
        $post->setStatus(SocialPostStatus::Publishing);
        $this->em->flush();

        if ($post->getId() !== null) {
            $this->bus->dispatch(new PublishSocialPostMessage($post->getId()));
        }

        return new JsonResponse(['status' => $post->getStatus()->value]);
    }
}
