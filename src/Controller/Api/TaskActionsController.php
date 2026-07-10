<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\DomainEventLog;
use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Enum\TaskPriority;
use App\Entity\Task;
use App\Entity\TaskAssignee;
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

    #[Route('/v1/tasks/{id}/change-status', name: 'api_task_change_status', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
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

    #[Route('/v1/tasks/{id}/set-priority', name: 'api_task_set_priority', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
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

    #[Route('/v1/tasks/{id}/set-prio-flag', name: 'api_task_set_prio_flag', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function setPrioFlag(string $id, Request $request): JsonResponse
    {
        $task = $this->ownedTask($id, WorktidePermission::EDIT);
        $body = $this->body($request);
        $task->setIsPrio((bool) ($body['isPrio'] ?? true));
        $this->em->flush();
        return new JsonResponse(['id' => $task->getId()?->toRfc4122(), 'isPrio' => $task->isPrio()]);
    }

    #[Route('/v1/tasks/{id}/set-assignees', name: 'api_task_set_assignees', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function setAssignees(string $id, Request $request): JsonResponse
    {
        $task = $this->ownedTask($id, WorktidePermission::EDIT);
        $body = $this->body($request);
        $userIds = $body['userIds'] ?? [];
        if (!\is_array($userIds)) {
            throw new BadRequestHttpException('userIds must be an array of UUIDs.');
        }
        $project = $task->getProject();
        if (\count($userIds) > 1 && $project !== null && !$project->isMultiAssignmentAllowed()) {
            throw new BadRequestHttpException('Project does not allow multi-assignment.');
        }
        if (\count($userIds) > 0 && $project === null) {
            throw new BadRequestHttpException('Private tasks (no project) cannot be assigned to other users.');
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

        // Assignees went polymorphic (User|Team); the old setAssignees(User[])
        // replace-all is gone. This endpoint only manages USER assignment
        // (it takes userIds), so we sync the user-principals and leave any
        // team-principals untouched — wiping team assignments as a side effect
        // of setting users would be surprising data loss.
        //
        // We diff rather than remove-all-then-add: the unique key is
        // (task, principal_type, principal_id), and Doctrine orders INSERTs
        // before DELETEs within one flush — so re-adding an already-present
        // user would collide with its own not-yet-deleted row. Only touch
        // what actually changed.
        $desiredUserIds = array_map(static fn (User $u) => $u->getId()?->toRfc4122(), $users);
        $existingUserIds = [];
        $removedUserIris = [];
        foreach ($task->getAssignedPrincipals()->toArray() as $existing) {
            \assert($existing instanceof TaskAssignee);
            if ($existing->getPrincipalType() !== AssigneePrincipalType::User) {
                continue;
            }
            $pid = $existing->getPrincipalId()->toRfc4122();
            if (\in_array($pid, $desiredUserIds, true)) {
                $existingUserIds[] = $pid;       // keep
            } else {
                $task->removeAssignedPrincipal($existing); // no longer wanted
                $removedUserIris[] = '/v1/users/' . $pid;
            }
        }
        $addedUserIris = [];
        foreach ($users as $u) {
            if (\in_array($u->getId()?->toRfc4122(), $existingUserIds, true)) {
                continue; // already assigned
            }
            $task->addAssignedPrincipal(
                (new TaskAssignee())
                    ->setPrincipalType(AssigneePrincipalType::User)
                    ->setPrincipalId($u->getId())
            );
            $addedUserIris[] = '/v1/users/' . $u->getId()?->toRfc4122();
        }
        $this->em->flush();

        // Assignee CHANGES on an existing task aren't picked up by the generic
        // emitter (TaskAssignee join rows aren't TRACKED, and the ManyToMany-ish
        // edit never dirties the Task itself). Emit an explicit domain event so
        // TaskAssignedResolver notifies the newly-assigned users — the "future
        // set-assignees controller" the emitter's docblock anticipated.
        if ($addedUserIris !== [] || $removedUserIris !== []) {
            $actor = $this->security->getUser();
            $this->em->persist(new DomainEventLog(
                'task.assignees_changed',
                'Task',
                $task->getId(),
                $task->getWorkspace(),
                $actor instanceof User ? $actor : null,
                [
                    'addedUsers' => $addedUserIris,
                    'removedUsers' => $removedUserIris,
                ],
            ));
            $this->em->flush();
        }

        return new JsonResponse([
            'id' => $task->getId()?->toRfc4122(),
            'assignees' => array_map(fn (User $u) => [
                'id' => $u->getId()?->toRfc4122(),
                'email' => $u->getEmail(),
                'fullName' => $u->getFullName(),
            ], $users),
        ]);
    }

    #[Route('/v1/tasks/{id}/copy', name: 'api_task_copy', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
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
        // Clone every assignment (User AND Team). getAssignees() now returns
        // bare user-IRIs, so we copy the principals directly to preserve both
        // kinds.
        foreach ($source->getAssignedPrincipals() as $p) {
            \assert($p instanceof TaskAssignee);
            $copy->addAssignedPrincipal(
                (new TaskAssignee())
                    ->setPrincipalType($p->getPrincipalType())
                    ->setPrincipalId($p->getPrincipalId())
            );
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

    #[Route('/v1/tasks/{id}/close', name: 'api_task_close', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
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

    #[Route('/v1/tasks/{id}/reopen', name: 'api_task_reopen', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function reopen(string $id): JsonResponse
    {
        $task = $this->ownedTask($id, WorktidePermission::EDIT);
        $task->reopen();
        $this->em->flush();
        return new JsonResponse(['id' => $task->getId()?->toRfc4122(), 'closedOn' => null]);
    }

    #[Route('/v1/tasks/by-key/{identifier}', name: 'api_task_by_key', requirements: ['identifier' => '[A-Z][A-Z0-9-]{0,30}'], methods: ['GET'])]
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
            'projectId' => $task->getProject()?->getId()?->toRfc4122(),
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
