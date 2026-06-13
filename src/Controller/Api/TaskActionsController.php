<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\TaskPriority;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Repository\TaskStatusRepository;
use App\Repository\UserRepository;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Task action endpoints — match awork's dedicated change-* operations.
 *
 *   POST /v1/tasks/{id}/change-status     { statusId }
 *   POST /v1/tasks/{id}/set-priority      { priority: "low|normal|high|urgent" }
 *   POST /v1/tasks/{id}/set-prio-flag     { isPrio: true|false }
 *   POST /v1/tasks/{id}/set-assignees     { userIds: ["uuid", ...] }
 *   POST /v1/tasks/{id}/copy
 *   POST /v1/tasks/{id}/close
 *   POST /v1/tasks/{id}/reopen
 *   GET  /v1/tasks/by-key/{identifier}    (e.g. WORK-42)
 */
final class TaskActionsController
{
    public function __construct(
        private readonly Security $security,
        private readonly TaskRepository $tasks,
        private readonly TaskStatusRepository $statuses,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/v1/tasks/{id}/change-status', name: 'api_task_change_status', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function changeStatus(string $id, Request $request): JsonResponse
    {
        $task = $this->ownedTask($id, WorktidePermission::EDIT);
        $body = $this->body($request);
        $statusIdRaw = $body['statusId'] ?? null;
        if (!\is_string($statusIdRaw)) {
            throw new BadRequestHttpException('Field statusId required.');
        }
        try {
            $status = $this->statuses->find(Uuid::fromString($statusIdRaw));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('statusId must be a UUID.');
        }
        if (!$status instanceof TaskStatus || $status->getWorkspace() !== $task->getWorkspace()) {
            throw new BadRequestHttpException('Status not found in this workspace.');
        }
        $task->setStatus($status);
        if ($status->isCompleted() && $task->getClosedOn() === null) {
            $user = $this->security->getUser();
            if ($user instanceof User) {
                $task->close($user);
            }
        } elseif (!$status->isCompleted() && $task->getClosedOn() !== null) {
            $task->reopen();
        }
        $this->em->flush();
        return new JsonResponse(['id' => $task->getId()?->toRfc4122(), 'statusId' => $status->getId()?->toRfc4122()]);
    }

    #[Route('/v1/tasks/{id}/set-priority', name: 'api_task_set_priority', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function setPriority(string $id, Request $request): JsonResponse
    {
        $task = $this->ownedTask($id, WorktidePermission::EDIT);
        $body = $this->body($request);
        $value = $body['priority'] ?? null;
        if (!\is_string($value)) {
            throw new BadRequestHttpException('Field priority required.');
        }
        $priority = TaskPriority::tryFrom($value);
        if ($priority === null) {
            throw new BadRequestHttpException('priority must be one of: low, normal, high, urgent.');
        }
        $task->setPriority($priority);
        $this->em->flush();
        return new JsonResponse(['id' => $task->getId()?->toRfc4122(), 'priority' => $priority->value]);
    }

    #[Route('/v1/tasks/{id}/set-prio-flag', name: 'api_task_set_prio_flag', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function setPrioFlag(string $id, Request $request): JsonResponse
    {
        $task = $this->ownedTask($id, WorktidePermission::EDIT);
        $body = $this->body($request);
        $task->setIsPrio((bool) ($body['isPrio'] ?? true));
        $this->em->flush();
        return new JsonResponse(['id' => $task->getId()?->toRfc4122(), 'isPrio' => $task->isPrio()]);
    }

    #[Route('/v1/tasks/{id}/set-assignees', name: 'api_task_set_assignees', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function setAssignees(string $id, Request $request): JsonResponse
    {
        $task = $this->ownedTask($id, WorktidePermission::EDIT);
        $body = $this->body($request);
        $userIds = $body['userIds'] ?? [];
        if (!\is_array($userIds)) {
            throw new BadRequestHttpException('userIds must be an array of UUIDs.');
        }
        if (\count($userIds) > 1 && !$task->getProject()->isMultiAssignmentAllowed()) {
            throw new BadRequestHttpException('Project does not allow multi-assignment.');
        }

        $users = [];
        foreach ($userIds as $raw) {
            if (!\is_string($raw)) {
                throw new BadRequestHttpException('Each userId must be a UUID string.');
            }
            try {
                $u = $this->users->find(Uuid::fromString($raw));
            } catch (\InvalidArgumentException) {
                throw new BadRequestHttpException("Invalid UUID: {$raw}");
            }
            if (!$u instanceof User) {
                throw new BadRequestHttpException("User not found: {$raw}");
            }
            $users[] = $u;
        }

        $task->setAssignees($users);
        $this->em->flush();

        return new JsonResponse([
            'id' => $task->getId()?->toRfc4122(),
            'assignees' => array_map(fn (User $u) => [
                'id' => $u->getId()?->toRfc4122(),
                'email' => $u->getEmail(),
                'fullName' => $u->getFullName(),
            ], $users),
        ]);
    }

    #[Route('/v1/tasks/{id}/copy', name: 'api_task_copy', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function copy(string $id): JsonResponse
    {
        $source = $this->ownedTask($id, WorktidePermission::VIEW);
        if (!$this->security->isGranted(WorktidePermission::EDIT, $source->getProject())) {
            throw new AccessDeniedHttpException('Cannot create tasks in this project.');
        }

        $copy = (new Task())
            ->setWorkspace($source->getWorkspace())
            ->setProject($source->getProject())
            ->setIdentifier($source->getIdentifier() . '-COPY')
            ->setTitle($source->getTitle() . ' (Copy)')
            ->setDescription($source->getDescription())
            ->setStatus($source->getStatus())
            ->setPriority($source->getPriority())
            ->setEstimatedMinutes($source->getEstimatedMinutes())
            ->setIsPrio($source->isPrio())
            ->setIsHiddenForConnectUsers($source->isHiddenForConnectUsers())
            ->setPosition($source->getPosition() + 1);
        foreach ($source->getTags() as $tag) {
            $copy->addTag($tag);
        }
        foreach ($source->getAssignees() as $a) {
            $copy->addAssignee($a);
        }
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $copy->setCreatedBy($user);
        }
        $this->em->persist($copy);
        $this->em->flush();

        return new JsonResponse(
            ['id' => $copy->getId()?->toRfc4122(), 'identifier' => $copy->getIdentifier(), 'title' => $copy->getTitle()],
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route('/v1/tasks/{id}/close', name: 'api_task_close', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function close(string $id): JsonResponse
    {
        $task = $this->ownedTask($id, WorktidePermission::EDIT);
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $task->close($user);
            $this->em->flush();
        }
        return new JsonResponse(['id' => $task->getId()?->toRfc4122(), 'closedOn' => $task->getClosedOn()?->format(\DateTimeInterface::ATOM)]);
    }

    #[Route('/v1/tasks/{id}/reopen', name: 'api_task_reopen', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function reopen(string $id): JsonResponse
    {
        $task = $this->ownedTask($id, WorktidePermission::EDIT);
        $task->reopen();
        $this->em->flush();
        return new JsonResponse(['id' => $task->getId()?->toRfc4122(), 'closedOn' => null]);
    }

    #[Route('/v1/tasks/by-key/{identifier}', name: 'api_task_by_key', host: 'api.worktide.ddev.site', requirements: ['identifier' => '[A-Z][A-Z0-9-]{0,30}'], methods: ['GET'])]
    public function getByKey(string $identifier): JsonResponse
    {
        $task = $this->tasks->findOneBy(['identifier' => strtoupper($identifier)]);
        if (!$task instanceof Task) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $task)) {
            throw new AccessDeniedHttpException();
        }
        return new JsonResponse([
            'id' => $task->getId()?->toRfc4122(),
            'identifier' => $task->getIdentifier(),
            'title' => $task->getTitle(),
            'projectId' => $task->getProject()->getId()?->toRfc4122(),
        ]);
    }

    private function ownedTask(string $id, string $permission): Task
    {
        $task = $this->tasks->find(Uuid::fromString($id));
        if (!$task instanceof Task) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted($permission, $task)) {
            throw new AccessDeniedHttpException();
        }
        return $task;
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $raw = (string) $request->getContent();
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException('Invalid JSON: ' . $e->getMessage(), $e);
        }
        return \is_array($decoded) ? $decoded : [];
    }
}
