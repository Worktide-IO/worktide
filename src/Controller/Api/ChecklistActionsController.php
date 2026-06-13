<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\ChecklistItem;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Convert all checklist items of a Task into Subtasks (Task entities with
 * `parent` set to this task). Mirrors awork's
 * post-task-checklist-items-to-subtasks endpoint.
 *
 *   POST /v1/tasks/{id}/checklist-items-to-subtasks
 *
 * The checklist items are removed after conversion. Each becomes a Task in
 * the same project with the checklist item's name as title; if the
 * checklist item was already done, the new subtask is assigned the same
 * status as its parent's "completed" status when one exists.
 */
final class ChecklistActionsController
{
    public function __construct(
        private readonly Security $security,
        private readonly TaskRepository $tasks,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/tasks/{id}/checklist-items-to-subtasks',
        name: 'api_checklist_to_subtasks',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function toSubtasks(string $id): JsonResponse
    {
        $task = $this->tasks->find(Uuid::fromString($id));
        if (!$task instanceof Task) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $task)) {
            throw new AccessDeniedHttpException();
        }

        $user = $this->security->getUser();
        $created = 0;

        $items = $task->getChecklistItems()->toArray();
        foreach ($items as $i => $item) {
            \assert($item instanceof ChecklistItem);
            $subtask = (new Task())
                ->setWorkspace($task->getWorkspace())
                ->setProject($task->getProject())
                ->setIdentifier(sprintf('%s.%d', $task->getIdentifier(), $i + 1))
                ->setTitle($item->getName())
                ->setStatus($task->getStatus())
                ->setPriority($task->getPriority())
                ->setParent($task)
                ->setPosition($i);
            if ($user instanceof User) {
                $subtask->setCreatedBy($user);
            }
            $this->em->persist($subtask);
            $this->em->remove($item);
            $created++;
        }
        if ($created > 0) {
            $this->em->flush();
        }
        return new JsonResponse(['converted' => $created, 'taskId' => $task->getId()?->toRfc4122()]);
    }
}
