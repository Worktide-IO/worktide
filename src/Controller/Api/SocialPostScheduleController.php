<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\SocialPostStatus;
use App\Entity\SocialPost;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Set or clear a post's intended publish time:
 *
 *   POST /v1/social_posts/{id}/schedule { "scheduledAt": "2026-07-01T09:00:00Z" | null }
 *
 * Requires EDIT. Only sets the time on the draft — going live still happens via
 * {@see SocialPostApproveController} (which routes a future time to Scheduled).
 */
final class SocialPostScheduleController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/social_posts/{id}/schedule',
        name: 'api_social_post_schedule',
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
        if (\in_array($post->getStatus(), [SocialPostStatus::Published, SocialPostStatus::Publishing, SocialPostStatus::Canceled], true)) {
            throw new ConflictHttpException(sprintf('Cannot reschedule a post in "%s" state.', $post->getStatus()->value));
        }

        $data = json_decode($request->getContent(), true);
        $data = \is_array($data) ? $data : [];
        $raw = $data['scheduledAt'] ?? null;

        if ($raw === null) {
            $post->setScheduledAt(null);
        } else {
            if (!\is_string($raw)) {
                throw new BadRequestHttpException('"scheduledAt" must be an ISO-8601 string or null.');
            }
            try {
                $when = new \DateTimeImmutable($raw);
            } catch (\Exception) {
                throw new BadRequestHttpException('"scheduledAt" is not a valid date/time.');
            }
            if ($when <= new \DateTimeImmutable()) {
                throw new BadRequestHttpException('"scheduledAt" must be in the future.');
            }
            $post->setScheduledAt($when);
        }
        $this->em->flush();

        return new JsonResponse(['scheduledAt' => $post->getScheduledAt()?->format(\DateTimeInterface::ATOM)]);
    }
}
