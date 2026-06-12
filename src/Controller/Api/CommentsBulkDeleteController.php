<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Comment;
use App\Entity\Enum\CommentTarget;
use App\Entity\Project;
use App\Entity\Task;
use App\Repository\CommentRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Bulk-delete all comments attached to a project or task.
 *
 * Soft-delete only — sets `deletedAt` on every Comment row; nothing is
 * physically removed. Requires EDIT on the parent (workspace member level).
 *
 * 204 No Content on success. Response header `X-Deleted-Count` carries the
 * number of comments that were marked deleted for visibility in logs.
 */
final class CommentsBulkDeleteController
{
    public function __construct(
        private readonly Security $security,
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
        private readonly CommentRepository $comments,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/projects/{id}/comments',
        name: 'api_project_comments_bulk_delete',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['DELETE'],
    )]
    public function deleteProjectComments(string $id): Response
    {
        $project = $this->projects->find(Uuid::fromString($id));
        if (!$project instanceof Project) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $project)) {
            throw new AccessDeniedHttpException();
        }
        return $this->softDeleteAll(CommentTarget::Project, $project->getId());
    }

    #[Route(
        path: '/v1/tasks/{id}/comments',
        name: 'api_task_comments_bulk_delete',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['DELETE'],
    )]
    public function deleteTaskComments(string $id): Response
    {
        $task = $this->tasks->find(Uuid::fromString($id));
        if (!$task instanceof Task) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $task)) {
            throw new AccessDeniedHttpException();
        }
        return $this->softDeleteAll(CommentTarget::Task, $task->getId());
    }

    private function softDeleteAll(CommentTarget $target, Uuid $targetId): Response
    {
        $rows = $this->comments->findBy([
            'target' => $target,
            'targetId' => $targetId,
            'deletedAt' => null,
        ]);

        $count = 0;
        foreach ($rows as $row) {
            \assert($row instanceof Comment);
            $row->softDelete();
            $count++;
        }
        if ($count > 0) {
            $this->em->flush();
        }

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('X-Deleted-Count', (string) $count);
        return $response;
    }
}
