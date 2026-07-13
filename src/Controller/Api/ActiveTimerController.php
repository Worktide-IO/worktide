<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\ActiveTimer;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TimeEntry;
use App\Entity\TypeOfWork;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Repository\ActiveTimerRepository;
use App\Service\Timer\TimerCloser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Stopwatch endpoints on top of {@see ActiveTimer}.
 *
 *   POST /v1/timers/start   { taskId?, projectId?, typeOfWorkId?, description?, isBillable? }
 *   POST /v1/timers/stop    →  finalises into a TimeEntry, removes ActiveTimer
 *   POST /v1/timers/cancel  →  discards without persisting a TimeEntry
 *   GET  /v1/timers/current →  current timer for the authenticated user
 *
 * One timer per user at a time — calling /start while another timer is
 * running implicitly stops the previous one (returns both the closed
 * TimeEntry id and the new timer's id).
 *
 * Workspace context: the timer inherits its workspace from the task/project
 * if either is provided; otherwise from the X-Workspace-Id header. A user
 * can only start a timer in a workspace they're a member of.
 */
final class ActiveTimerController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly ActiveTimerRepository $timers,
        private readonly TimerCloser $timerCloser,
    ) {}

    #[Route(path: '/v1/timers/current', name: 'api_timer_current', methods: ['GET'])]
    public function current(): JsonResponse
    {
        $user = $this->user();
        $timer = $this->timers->findForUser($user);
        if ($timer === null) {
            return new JsonResponse(['running' => false]);
        }
        return new JsonResponse($this->snapshot($timer));
    }

    #[Route(path: '/v1/timers/start', name: 'api_timer_start', methods: ['POST'])]
    public function start(Request $request): JsonResponse
    {
        $user = $this->user();
        $body = $this->body($request);

        $task = $this->resolveTask($body);
        $project = $this->resolveProject($body) ?? $task?->getProject();
        $workspace = $this->resolveWorkspace($request, $user, $task, $project);
        $this->assertMembership($user, $workspace);

        $closed = $this->stopExistingIfAny($user, $workspace);
        if ($closed !== null) {
            // Flush the close/remove BEFORE inserting the new timer — the
            // active_timer_user_unique constraint would otherwise trip on the
            // implicit insert-before-delete ordering.
            $this->em->flush();
        }

        $timer = (new ActiveTimer())
            ->setUser($user)
            ->setWorkspace($workspace)
            ->setStartedAt(new \DateTimeImmutable())
            ->setTask($task)
            ->setProject($project)
            ->setTypeOfWork($this->resolveTypeOfWork($body))
            ->setDescription(isset($body['description']) && \is_string($body['description']) ? $body['description'] : null)
            ->setIsBillable($body['isBillable'] ?? true);
        $this->em->persist($timer);
        $this->em->flush();

        return new JsonResponse([
            'closedTimeEntryId' => $closed?->getId()?->toRfc4122(),
            ...$this->snapshot($timer),
        ], Response::HTTP_CREATED);
    }

    #[Route(path: '/v1/timers/stop', name: 'api_timer_stop', methods: ['POST'])]
    public function stop(): JsonResponse
    {
        $user = $this->user();
        $timer = $this->timers->findForUser($user);
        if ($timer === null) {
            throw new BadRequestHttpException('No timer running.');
        }
        $entry = $this->timerCloser->close($timer, new \DateTimeImmutable());
        $this->em->flush();
        return new JsonResponse([
            'timeEntryId' => $entry->getId()?->toRfc4122(),
            'durationMinutes' => $entry->getDurationMinutes(),
        ]);
    }

    #[Route(path: '/v1/timers/cancel', name: 'api_timer_cancel', methods: ['POST'])]
    public function cancel(): JsonResponse
    {
        $user = $this->user();
        $timer = $this->timers->findForUser($user);
        if ($timer === null) {
            return new JsonResponse(['cancelled' => false]);
        }
        $this->em->remove($timer);
        $this->em->flush();
        return new JsonResponse(['cancelled' => true]);
    }

    private function user(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }
        return $user;
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $raw = $request->getContent();
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Body must be JSON.');
        }
        return \is_array($decoded) ? $decoded : [];
    }

    private function resolveWorkspace(Request $request, User $user, ?Task $task, ?Project $project): Workspace
    {
        $ws = $task?->getWorkspace() ?? $project?->getWorkspace();
        if ($ws !== null) {
            return $ws;
        }
        $hdr = $request->headers->get('X-Workspace-Id');
        if (\is_string($hdr) && $hdr !== '') {
            try {
                $found = $this->em->find(Workspace::class, Uuid::fromString($hdr));
                if ($found !== null) {
                    return $found;
                }
            } catch (\InvalidArgumentException) {
                // fall through
            }
        }
        $membership = $this->em->getRepository(WorkspaceMember::class)->findOneBy(['user' => $user]);
        if ($membership === null) {
            throw new BadRequestHttpException('Could not resolve workspace — supply taskId, projectId, or X-Workspace-Id.');
        }
        return $membership->getWorkspace();
    }

    private function assertMembership(User $user, Workspace $workspace): void
    {
        $member = $this->em->getRepository(WorkspaceMember::class)->findOneBy([
            'workspace' => $workspace,
            'user' => $user,
        ]);
        if ($member === null) {
            throw new AccessDeniedHttpException('Not a member of this workspace.');
        }
    }

    /** @param array<string, mixed> $body */
    private function resolveTask(array $body): ?Task
    {
        $id = $body['taskId'] ?? null;
        if (!\is_string($id) || $id === '') {
            return null;
        }
        try {
            $task = $this->em->find(Task::class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('taskId is not a valid UUID.');
        }
        if ($task === null) {
            throw new NotFoundHttpException('Task not found.');
        }
        return $task;
    }

    /** @param array<string, mixed> $body */
    private function resolveProject(array $body): ?Project
    {
        $id = $body['projectId'] ?? null;
        if (!\is_string($id) || $id === '') {
            return null;
        }
        try {
            $project = $this->em->find(Project::class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('projectId is not a valid UUID.');
        }
        if ($project === null) {
            throw new NotFoundHttpException('Project not found.');
        }
        return $project;
    }

    /** @param array<string, mixed> $body */
    private function resolveTypeOfWork(array $body): ?TypeOfWork
    {
        $id = $body['typeOfWorkId'] ?? null;
        if (!\is_string($id) || $id === '') {
            return null;
        }
        try {
            return $this->em->find(TypeOfWork::class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function stopExistingIfAny(User $user, Workspace $workspace): ?TimeEntry
    {
        $timer = $this->timers->findForUser($user);
        if ($timer === null) {
            return null;
        }
        return $this->timerCloser->close($timer, new \DateTimeImmutable());
    }

    /** @return array<string, mixed> */
    private function snapshot(ActiveTimer $timer): array
    {
        return [
            'running' => true,
            'timerId' => $timer->getId()?->toRfc4122(),
            'startedAt' => $timer->getStartedAt()->format(\DateTimeInterface::ATOM),
            'elapsedSeconds' => $timer->elapsedSeconds(),
            'taskId' => $timer->getTask()?->getId()?->toRfc4122(),
            'projectId' => $timer->getProject()?->getId()?->toRfc4122(),
            'typeOfWorkId' => $timer->getTypeOfWork()?->getId()?->toRfc4122(),
            'description' => $timer->getDescription(),
            'isBillable' => $timer->isBillable(),
        ];
    }
}
