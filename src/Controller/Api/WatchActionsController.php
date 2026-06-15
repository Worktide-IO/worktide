<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Document;
use App\Entity\Enum\WatchableTarget;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Entity\Watch;
use App\Repository\WatchRepository;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Watch / unwatch endpoints — subscribing the current user to mutation
 * events on a polymorphic target.
 *
 *   POST   /v1/watch   { target, targetId }   → idempotent watch
 *   DELETE /v1/watch   { target, targetId }   → idempotent unwatch
 *   GET    /v1/watch/me?target=&targetId=     → 200 { watching, watchersCount }
 *
 * The URL pins authorization to the authenticated user — there's no path
 * accepting "another user's" subscription, so no voter for "edit-other-
 * users-watches" needs to exist. The VIEW voter on the target still gates
 * subscribing: you can only watch what you can see.
 */
final class WatchActionsController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly WatchRepository $watches,
    ) {}

    #[Route(
        path: '/v1/watch',
        name: 'api_watch_create',
        host: 'api.worktide.ddev.site',
        methods: ['POST'],
    )]
    public function watch(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        ['target' => $target, 'targetId' => $targetId] = $this->body($request);
        $entity = $this->resolveTarget($target, $targetId);
        $this->assertViewable($entity);

        $workspace = $this->workspaceOf($entity);
        $existing = $this->watches->findOneByTuple($workspace, $target, $targetId, $user);
        if ($existing !== null) {
            return new JsonResponse($this->snapshot($existing, $target, $targetId));
        }

        $watch = (new Watch())
            ->setWorkspace($workspace)
            ->setTarget($target)
            ->setTargetId($targetId)
            ->setUser($user);
        $this->em->persist($watch);
        $this->em->flush();

        return new JsonResponse($this->snapshot($watch, $target, $targetId), Response::HTTP_CREATED);
    }

    #[Route(
        path: '/v1/watch',
        name: 'api_watch_delete',
        host: 'api.worktide.ddev.site',
        methods: ['DELETE'],
    )]
    public function unwatch(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        ['target' => $target, 'targetId' => $targetId] = $this->body($request);
        $entity = $this->resolveTarget($target, $targetId);
        $workspace = $this->workspaceOf($entity);

        $existing = $this->watches->findOneByTuple($workspace, $target, $targetId, $user);
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();
        }
        return new JsonResponse([
            'watching' => false,
            'watchersCount' => $this->countWatchers($workspace, $target, $targetId),
        ]);
    }

    #[Route(
        path: '/v1/watch/me',
        name: 'api_watch_me',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function me(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $target = WatchableTarget::from((string) $request->query->get('target', ''));
        $targetId = Uuid::fromString((string) $request->query->get('targetId', ''));
        $entity = $this->resolveTarget($target, $targetId);
        $this->assertViewable($entity);
        $workspace = $this->workspaceOf($entity);

        $existing = $this->watches->findOneByTuple($workspace, $target, $targetId, $user);
        return new JsonResponse([
            'watching' => $existing !== null,
            'watchersCount' => $this->countWatchers($workspace, $target, $targetId),
        ]);
    }

    private function requireUser(): User
    {
        $u = $this->security->getUser();
        if (!$u instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }
        return $u;
    }

    /**
     * @return array{target: WatchableTarget, targetId: Uuid}
     */
    private function body(Request $request): array
    {
        $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR | \JSON_OBJECT_AS_ARRAY);
        if (!is_array($raw)) {
            throw new BadRequestHttpException('Body must be a JSON object.');
        }
        $t = $raw['target'] ?? null;
        $i = $raw['targetId'] ?? null;
        if (!is_string($t) || !is_string($i)) {
            throw new BadRequestHttpException('target + targetId required.');
        }
        try {
            return ['target' => WatchableTarget::from($t), 'targetId' => Uuid::fromString($i)];
        } catch (\Throwable $e) {
            throw new BadRequestHttpException('Invalid target or targetId: ' . $e->getMessage());
        }
    }

    private function resolveTarget(WatchableTarget $target, Uuid $id): Task|Project|Document
    {
        $class = match ($target) {
            WatchableTarget::Task => Task::class,
            WatchableTarget::Project => Project::class,
            WatchableTarget::Document => Document::class,
        };
        $entity = $this->em->find($class, $id);
        if ($entity === null) {
            throw new NotFoundHttpException();
        }
        return $entity;
    }

    private function assertViewable(Task|Project|Document $entity): void
    {
        if (!$this->security->isGranted(WorktidePermission::VIEW, $entity)) {
            throw new AccessDeniedHttpException();
        }
    }

    private function workspaceOf(Task|Project|Document $entity): \App\Entity\Workspace
    {
        return $entity->getWorkspace();
    }

    private function countWatchers(\App\Entity\Workspace $workspace, WatchableTarget $target, Uuid $targetId): int
    {
        return (int) $this->em
            ->createQueryBuilder()
            ->select('COUNT(w.id)')
            ->from(Watch::class, 'w')
            ->where('w.workspace = :ws AND w.target = :t AND w.targetId = :id')
            ->setParameter('ws', $workspace)
            ->setParameter('t', $target->value)
            ->setParameter('id', $targetId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Watch $watch, WatchableTarget $target, Uuid $targetId): array
    {
        $workspace = $watch->getWorkspace();
        return [
            'watching' => true,
            'watchId' => $watch->getId()?->toRfc4122(),
            'watchersCount' => $this->countWatchers($workspace, $target, $targetId),
        ];
    }
}
