<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\SocialPostStatus;
use App\Entity\SocialPost;
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
 * Approve a submitted post and take it live (human-in-the-loop gate):
 *
 *   POST /v1/social_posts/{id}/approve
 *
 * Requires workspace MANAGE. If `scheduledAt` is set in the future the post
 * goes to Scheduled (the publish-due command fans it out at that time);
 * otherwise it goes straight to Publishing and the fan-out is dispatched now.
 */
final class SocialPostApproveController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {}

    #[Route(
        path: '/v1/social_posts/{id}/approve',
        name: 'api_social_post_approve',
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
        if (!\in_array($post->getStatus(), [SocialPostStatus::Draft, SocialPostStatus::PendingApproval], true)) {
            throw new ConflictHttpException(sprintf('Cannot approve a post in "%s" state.', $post->getStatus()->value));
        }
        if ($post->getTargets()->isEmpty()) {
            throw new ConflictHttpException('Post has no target networks.');
        }

        $post->setApprovedByUser($this->security->getUser() instanceof \App\Entity\User ? $this->security->getUser() : null);
        $post->setApprovedAt(new \DateTimeImmutable());

        $scheduledAt = $post->getScheduledAt();
        $dispatch = false;
        if ($scheduledAt !== null && $scheduledAt > new \DateTimeImmutable()) {
            $post->setStatus(SocialPostStatus::Scheduled);
        } else {
            $post->setStatus(SocialPostStatus::Publishing);
            $dispatch = true;
        }
        $this->em->flush();

        if ($dispatch && $post->getId() !== null) {
            $this->bus->dispatch(new PublishSocialPostMessage($post->getId()));
        }

        return new JsonResponse(['status' => $post->getStatus()->value]);
    }
}
