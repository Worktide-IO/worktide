<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Channels\AdapterRegistry;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Security\Voter\WorktidePermission;
use App\Service\Social\SocialPostValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Dry-run validation for the composer UI:
 *
 *   POST /v1/social_posts/{id}/preview
 *
 * Requires VIEW. Returns, per target, the effective text, its length against
 * the network's limit, and any validation problems — so the SPA can warn the
 * user before they approve. No state change.
 */
final class SocialPostPreviewController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly SocialPostValidator $validator,
        private readonly AdapterRegistry $registry,
    ) {}

    #[Route(
        path: '/v1/social_posts/{id}/preview',
        name: 'api_social_post_preview',
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
        if (!$this->security->isGranted(WorktidePermission::VIEW, $post)) {
            throw new AccessDeniedHttpException();
        }

        $targets = [];
        $allValid = true;
        foreach ($post->getTargets() as $target) {
            \assert($target instanceof SocialPostTarget);
            $code = $target->getChannel()->getAdapterCode();
            $adapter = $this->registry->trySocial($code);
            $text = $target->effectiveBody();
            $problems = $this->validator->validateTarget($target);
            if ($problems !== []) {
                $allValid = false;
            }
            $targets[] = [
                'targetId' => $target->getId()?->toRfc4122(),
                'channelId' => $target->getChannel()->getId()?->toRfc4122(),
                'adapterCode' => $code,
                'network' => $adapter?->getLabel() ?? $code,
                'text' => $text,
                'length' => mb_strlen($text),
                'maxLength' => $adapter?->maxLength(),
                'problems' => $problems,
            ];
        }

        return new JsonResponse([
            'valid' => $allValid,
            'targets' => $targets,
        ]);
    }
}
