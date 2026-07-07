<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\SocialPostStatus;
use App\Entity\Enum\SocialPostTargetStatus;
use App\Entity\SocialPostTarget;
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
 * Retry a single failed network target:
 *
 *   POST /v1/social_post_targets/{id}/retry
 *
 * Requires workspace MANAGE. Resets the failed target to Queued (attempt
 * counter cleared), flips the parent post back to Publishing, and dispatches
 * the fan-out — which re-attempts only the queued target.
 */
final class SocialPostTargetRetryController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {}

    #[Route(
        path: '/v1/social_post_targets/{id}/retry',
        name: 'api_social_post_target_retry',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function __invoke(string $id): JsonResponse
    {
        $target = $this->em->find(SocialPostTarget::class, Uuid::fromString($id));
        if ($target === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::MANAGE, $target)) {
            throw new AccessDeniedHttpException();
        }
        if ($target->getStatus() !== SocialPostTargetStatus::Failed) {
            throw new ConflictHttpException(sprintf('Only a failed target can be retried (is "%s").', $target->getStatus()->value));
        }

        $target->setStatus(SocialPostTargetStatus::Queued);
        $target->setAttemptCount(0);
        $target->setErrorReason(null);

        $post = $target->getSocialPost();
        $post->setStatus(SocialPostStatus::Publishing);
        $this->em->flush();

        if ($post->getId() !== null) {
            $this->bus->dispatch(new PublishSocialPostMessage($post->getId()));
        }

        return new JsonResponse(['status' => $target->getStatus()->value]);
    }
}
