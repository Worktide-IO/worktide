<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\Enum\SocialPostStatus;
use App\Entity\Project;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Entity\User;
use App\Repository\SocialPostRepository;
use App\Service\Portal\PortalAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Customer-portal "Social-Freigabe" (wireframe screen 6): the customer reviews
 * AI-drafted social posts for their projects and approves / requests changes /
 * rejects them before anything is published (human-in-the-loop).
 *
 * Approve moves a PendingApproval post to Scheduled (approved + queued); the
 * existing staff publish pipeline still performs the actual send — a portal
 * click never publishes directly. Scoped to the contact's external projects
 * via {@see SocialPost::$project}. Gated by the `social` feature flag.
 */
final class PortalSocialController
{
    private const STATUS_LABELS = [
        'draft' => 'Entwurf',
        'pending_approval' => 'Wartet auf Freigabe',
        'scheduled' => 'Freigegeben · geplant',
        'publishing' => 'Wird veröffentlicht',
        'published' => 'Veröffentlicht',
        'partially_failed' => 'Teilweise fehlgeschlagen',
        'failed' => 'Fehlgeschlagen',
        'canceled' => 'Abgelehnt',
    ];

    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly SocialPostRepository $posts,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {}

    #[Route(
        path: '/v1/portal/social',
        name: 'api_portal_social_list',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function list(): JsonResponse
    {
        $this->portal->assertFeatureEnabled('social');

        return new JsonResponse([
            'posts' => array_map($this->postDto(...), $this->posts->findForPortalProjects($this->portal->allowedProjects())),
        ]);
    }

    #[Route(
        path: '/v1/portal/social/{id}/approve',
        name: 'api_portal_social_approve',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function approve(string $id): JsonResponse
    {
        $post = $this->findApprovableOr404($id);

        $post->setApprovedByUser($this->portalUser())
            ->setApprovedAt(new \DateTimeImmutable())
            ->setChangeRequestNote(null)
            // Approved + queued; the staff publish pipeline does the actual send.
            ->setStatus(SocialPostStatus::Scheduled);
        $this->em->flush();

        return new JsonResponse($this->postDto($post));
    }

    #[Route(
        path: '/v1/portal/social/{id}/reject',
        name: 'api_portal_social_reject',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function reject(string $id): JsonResponse
    {
        $post = $this->findApprovableOr404($id);
        $post->setStatus(SocialPostStatus::Canceled);
        $this->em->flush();

        return new JsonResponse($this->postDto($post));
    }

    #[Route(
        path: '/v1/portal/social/{id}/request-change',
        name: 'api_portal_social_request_change',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function requestChange(string $id, Request $request): JsonResponse
    {
        $post = $this->findApprovableOr404($id);

        $message = \is_string($this->body($request)['message'] ?? null) ? trim($this->body($request)['message']) : '';
        if ($message === '') {
            throw new BadRequestHttpException('message required.');
        }

        // Send it back to the agency as a draft carrying the requested change.
        $post->setChangeRequestNote($message)->setStatus(SocialPostStatus::Draft);
        $this->em->flush();

        return new JsonResponse($this->postDto($post));
    }

    /**
     * Load a post the contact may act on: in one of their projects and still
     * awaiting a decision (Draft or PendingApproval). Otherwise 404/409.
     */
    private function findApprovableOr404(string $id): SocialPost
    {
        $this->portal->assertFeatureEnabled('social');
        $post = $this->loadOr404($id);

        if (!\in_array($post->getStatus(), [SocialPostStatus::Draft, SocialPostStatus::PendingApproval], true)) {
            throw new ConflictHttpException(sprintf('Post is in "%s" state.', $post->getStatus()->value));
        }
        return $post;
    }

    private function loadOr404(string $id): SocialPost
    {
        $post = $this->posts->find(Uuid::fromString($id));
        if (!$post instanceof SocialPost || $post->getDeletedAt() !== null || !$this->isAllowedProject($post->getProject())) {
            throw new NotFoundHttpException('Post not found.');
        }
        return $post;
    }

    private function isAllowedProject(?Project $project): bool
    {
        if ($project === null) {
            return false;
        }
        foreach ($this->portal->allowedProjects() as $allowed) {
            if ($allowed->getId()?->equals($project->getId()) === true) {
                return true;
            }
        }
        return false;
    }

    private function portalUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new NotFoundHttpException();
        }
        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function postDto(SocialPost $post): array
    {
        $status = $post->getStatus()->value;

        return [
            'id' => $post->getId()?->toRfc4122(),
            'body' => $post->getBody(),
            'mediaCount' => \count($post->getMediaRefs()),
            'status' => $status,
            'statusLabel' => self::STATUS_LABELS[$status] ?? $status,
            'scheduledAt' => $post->getScheduledAt()?->format(\DateTimeInterface::ATOM),
            'publishedAt' => $post->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'changeRequestNote' => $post->getChangeRequestNote(),
            'canDecide' => \in_array($post->getStatus(), [SocialPostStatus::Draft, SocialPostStatus::PendingApproval], true),
            'targets' => array_map(static fn (SocialPostTarget $t): array => [
                'channel' => $t->getChannel()->getName(),
                'permalink' => $t->getPermalink(),
            ], $post->getTargets()->toArray()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $raw = $request->getContent();
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Body must be valid JSON.');
        }
        return \is_array($decoded) ? $decoded : [];
    }
}
