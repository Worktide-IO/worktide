<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AIRecommendation;
use App\Entity\Conversation;
use App\Entity\Enum\RecommendationStatus;
use App\Entity\Enum\RecommendationTarget;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Security\Voter\WorktidePermission;
use App\Service\Ai\RecommendationApplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Human-in-the-loop review of an {@see AIRecommendation}:
 *
 *   POST /v1/ai_recommendations/{id}/accept  → apply the suggestion to the ticket
 *   POST /v1/ai_recommendations/{id}/reject  → dismiss it
 *
 * Only Pending recommendations can be reviewed. Accept is the ONLY path that
 * mutates the ticket (via {@see RecommendationApplier}); reject just records the
 * decision. Access is gated on the underlying ticket's permission.
 */
final class RecommendationReviewController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly RecommendationApplier $applier,
    ) {}

    #[Route(
        path: '/v1/ai_recommendations/{id}/accept',
        name: 'api_ai_recommendation_accept',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function accept(string $id, Request $request): JsonResponse
    {
        [$recommendation, $user] = $this->loadAndAuthorize($id);

        // Optional project override (for TicketFromConversation when no project
        // could be suggested automatically).
        $project = $this->resolveProjectOverride($request);

        try {
            $this->applier->apply($recommendation, $user, $project);
        } catch (\DomainException $e) {
            // e.g. a ticket suggestion with no project — the client must supply one.
            throw new ConflictHttpException($e->getMessage());
        }
        $this->settle($recommendation, RecommendationStatus::Accepted, $user);

        return $this->respond($recommendation);
    }

    private function resolveProjectOverride(Request $request): ?Project
    {
        $data = json_decode($request->getContent() ?: '[]', true);
        $ref = \is_array($data) ? ($data['project'] ?? null) : null;
        if (!\is_string($ref) || $ref === '') {
            return null;
        }
        $uuid = str_contains($ref, '/') ? substr((string) strrchr($ref, '/'), 1) : $ref;
        try {
            return $this->em->find(Project::class, Uuid::fromString($uuid));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    #[Route(
        path: '/v1/ai_recommendations/{id}/reject',
        name: 'api_ai_recommendation_reject',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function reject(string $id): JsonResponse
    {
        [$recommendation, $user] = $this->loadAndAuthorize($id);

        $this->settle($recommendation, RecommendationStatus::Rejected, $user);

        return $this->respond($recommendation);
    }

    /**
     * @return array{0: AIRecommendation, 1: User}
     */
    private function loadAndAuthorize(string $id): array
    {
        $recommendation = $this->em->find(AIRecommendation::class, Uuid::fromString($id));
        if (!$recommendation instanceof AIRecommendation) {
            throw new NotFoundHttpException();
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $this->authorizeAgainstTicket($recommendation);

        if ($recommendation->getStatus() !== RecommendationStatus::Pending) {
            throw new ConflictHttpException(sprintf(
                'Recommendation already %s.',
                $recommendation->getStatus()->value,
            ));
        }

        return [$recommendation, $user];
    }

    /**
     * The recommendation is loaded via em->find (bypasses the workspace query
     * extension), so access must be checked explicitly against its ticket.
     */
    private function authorizeAgainstTicket(AIRecommendation $recommendation): void
    {
        if ($recommendation->getTarget() === RecommendationTarget::Task) {
            $task = $this->em->find(Task::class, $recommendation->getTargetId());
            if (!$task instanceof Task) {
                throw new NotFoundHttpException();
            }
            if (!$this->security->isGranted(WorktidePermission::EDIT, $task)) {
                throw new AccessDeniedHttpException();
            }

            return;
        }

        $conversation = $this->em->find(Conversation::class, $recommendation->getTargetId());
        if (!$conversation instanceof Conversation) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $conversation->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }
    }

    private function settle(AIRecommendation $recommendation, RecommendationStatus $status, User $user): void
    {
        $recommendation->setStatus($status);
        $recommendation->setReviewedBy($user);
        $recommendation->setReviewedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    private function respond(AIRecommendation $recommendation): JsonResponse
    {
        return new JsonResponse([
            'id' => $recommendation->getId()?->toRfc4122(),
            'status' => $recommendation->getStatus()->value,
        ]);
    }
}
