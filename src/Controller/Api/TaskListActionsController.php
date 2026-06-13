<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskList;
use App\Entity\TaskListEntry;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\TaskListEntryRepository;
use App\Repository\TaskListRepository;
use App\Repository\TaskRepository;
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
 * TaskList action endpoints matching awork's 13-op surface.
 *
 *   POST /v1/task-lists/{id}/add-tasks       { taskIds[] }
 *   POST /v1/task-lists/{id}/remove-tasks    { taskIds[] }
 *   POST /v1/task-lists/{id}/change-project  { projectId }
 *   POST /v1/task-lists/{id}/copy
 *   POST /v1/task-lists/{id}/set-archived    { isArchived }
 */
final class TaskListActionsController
{
    public function __construct(
        private readonly Security $security,
        private readonly TaskListRepository $lists,
        private readonly TaskListEntryRepository $entries,
        private readonly TaskRepository $tasks,
        private readonly ProjectRepository $projects,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/v1/task-lists/{id}/add-tasks', name: 'api_task_list_add_tasks', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function addTasks(string $id, Request $request): JsonResponse
    {
        $list = $this->ownedList($id, WorktidePermission::EDIT);
        $taskIds = $this->extractTaskIds($request);
        $added = 0;
        $skipped = 0;

        foreach ($taskIds as $taskId) {
            $task = $this->tasks->find($taskId);
            if (!$task instanceof Task) {
                $skipped++;
                continue;
            }
            if ($task->getProject() !== $list->getProject()) {
                $skipped++;
                continue;
            }
            $existing = $this->entries->findOneBy(['list' => $list, 'task' => $task]);
            if ($existing !== null) {
                $skipped++;
                continue;
            }
            $entry = (new TaskListEntry())
                ->setList($list)
                ->setTask($task)
                ->setPosition($this->nextPosition($list));
            $this->em->persist($entry);
            $added++;
        }
        if ($added > 0) {
            $this->em->flush();
        }
        return new JsonResponse(['added' => $added, 'skipped' => $skipped]);
    }

    #[Route('/v1/task-lists/{id}/remove-tasks', name: 'api_task_list_remove_tasks', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function removeTasks(string $id, Request $request): JsonResponse
    {
        $list = $this->ownedList($id, WorktidePermission::EDIT);
        $taskIds = $this->extractTaskIds($request);
        $removed = 0;

        foreach ($taskIds as $taskId) {
            $entry = $this->entries->createQueryBuilder('e')
                ->andWhere('e.list = :list')
                ->andWhere('e.task = :task')
                ->setParameter('list', $list)
                ->setParameter('task', $taskId, \Symfony\Bridge\Doctrine\Types\UuidType::NAME)
                ->getQuery()
                ->getOneOrNullResult();
            if ($entry instanceof TaskListEntry) {
                $this->em->remove($entry);
                $removed++;
            }
        }
        if ($removed > 0) {
            $this->em->flush();
        }
        return new JsonResponse(['removed' => $removed]);
    }

    #[Route('/v1/task-lists/{id}/change-project', name: 'api_task_list_change_project', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function changeProject(string $id, Request $request): JsonResponse
    {
        $list = $this->ownedList($id, WorktidePermission::EDIT);
        $body = $this->body($request);
        $raw = $body['projectId'] ?? null;
        if (!\is_string($raw)) {
            throw new BadRequestHttpException('Field projectId required.');
        }
        try {
            $target = $this->projects->find(Uuid::fromString($raw));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('projectId must be a UUID.');
        }
        if (!$target instanceof Project) {
            throw new NotFoundHttpException('Target project not found.');
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $target)) {
            throw new AccessDeniedHttpException('Cannot move list to this project.');
        }
        if ($target->getWorkspace() !== $list->getWorkspace()) {
            throw new BadRequestHttpException('Cross-workspace move not supported.');
        }

        // Removing entries that don't make sense in the new project (tasks
        // belong to the old project still).
        foreach ($list->getEntries() as $entry) {
            $this->em->remove($entry);
        }
        $list->setProject($target);
        $this->em->flush();
        return new JsonResponse(['id' => $list->getId()?->toRfc4122(), 'projectId' => $target->getId()?->toRfc4122()]);
    }

    #[Route('/v1/task-lists/{id}/copy', name: 'api_task_list_copy', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function copy(string $id): JsonResponse
    {
        $source = $this->ownedList($id, WorktidePermission::VIEW);
        if (!$this->security->isGranted(WorktidePermission::EDIT, $source->getProject())) {
            throw new AccessDeniedHttpException();
        }

        $copy = (new TaskList())
            ->setWorkspace($source->getWorkspace())
            ->setProject($source->getProject())
            ->setName($source->getName() . ' (Copy)')
            ->setColor($source->getColor())
            ->setPosition($source->getPosition() + 0.5)
            ->setIsArchived($source->isArchived())
            ->setIsHiddenForConnectUsers($source->isHiddenForConnectUsers());
        $this->em->persist($copy);

        // Copy entries: same tasks, same per-list order (clients reorder later).
        foreach ($source->getEntries() as $entry) {
            $newEntry = (new TaskListEntry())
                ->setList($copy)
                ->setTask($entry->getTask())
                ->setPosition($entry->getPosition());
            $this->em->persist($newEntry);
        }
        $this->em->flush();

        return new JsonResponse(
            ['id' => $copy->getId()?->toRfc4122(), 'name' => $copy->getName()],
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route('/v1/task-lists/{id}/set-archived', name: 'api_task_list_set_archived', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function setArchived(string $id, Request $request): JsonResponse
    {
        $list = $this->ownedList($id, WorktidePermission::EDIT);
        $body = $this->body($request);
        $list->setIsArchived((bool) ($body['isArchived'] ?? true));
        $this->em->flush();
        return new JsonResponse(['id' => $list->getId()?->toRfc4122(), 'isArchived' => $list->isArchived()]);
    }

    private function ownedList(string $id, string $permission): TaskList
    {
        try {
            $list = $this->lists->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid UUID');
        }
        if (!$list instanceof TaskList) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted($permission, $list)) {
            throw new AccessDeniedHttpException();
        }
        return $list;
    }

    /** @return list<Uuid> */
    private function extractTaskIds(Request $request): array
    {
        $body = $this->body($request);
        $raw = $body['taskIds'] ?? [];
        if (!\is_array($raw) || $raw === []) {
            throw new BadRequestHttpException('Field taskIds[] required, non-empty.');
        }
        $out = [];
        foreach ($raw as $r) {
            if (!\is_string($r)) {
                continue;
            }
            try {
                $out[] = Uuid::fromString($r);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }
        return $out;
    }

    private function nextPosition(TaskList $list): float
    {
        $max = 0.0;
        foreach ($list->getEntries() as $e) {
            if ($e->getPosition() > $max) {
                $max = $e->getPosition();
            }
        }
        return $max + 1.0;
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
