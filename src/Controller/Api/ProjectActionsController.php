<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Project;
use App\Entity\ProjectStatus;
use App\Entity\ProjectType;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\ProjectStatusRepository;
use App\Repository\ProjectTypeRepository;
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
 * Project action endpoints — match awork's dedicated change-* operations.
 *
 *   POST /v1/projects/{id}/change-status        { statusId }
 *   POST /v1/projects/{id}/change-project-type  { projectTypeId }
 *   POST /v1/projects/{id}/set-key              { projectKey }
 *   POST /v1/projects/{id}/close                — sets closedOn/closedBy
 *   POST /v1/projects/{id}/reopen
 *   GET  /v1/projects/by-key/{key}
 */
final class ProjectActionsController
{
    public function __construct(
        private readonly Security $security,
        private readonly ProjectRepository $projects,
        private readonly ProjectStatusRepository $statuses,
        private readonly ProjectTypeRepository $types,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/projects/{id}/change-status',
        name: 'api_project_change_status',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function changeStatus(string $id, Request $request): JsonResponse
    {
        $project = $this->ownedProject($id, WorktidePermission::EDIT);
        $statusId = $this->extractUuid($request, 'statusId');
        $status = $this->statuses->find($statusId);
        if (!$status instanceof ProjectStatus || $status->getWorkspace() !== $project->getWorkspace()) {
            throw new BadRequestHttpException('Status not found in this workspace.');
        }

        $project->setStatus($status);
        if ($status->isCompleted() && $project->getClosedOn() === null) {
            $user = $this->security->getUser();
            if ($user instanceof User) {
                $project->close($user);
            }
        } elseif (!$status->isCompleted() && $project->getClosedOn() !== null) {
            $project->reopen();
        }
        $this->em->flush();
        return new JsonResponse(['id' => $project->getId()?->toRfc4122(), 'statusId' => $status->getId()?->toRfc4122()]);
    }

    #[Route(
        path: '/v1/projects/{id}/change-project-type',
        name: 'api_project_change_project_type',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function changeProjectType(string $id, Request $request): JsonResponse
    {
        $project = $this->ownedProject($id, WorktidePermission::EDIT);
        $body = $this->body($request);
        $typeIdRaw = $body['projectTypeId'] ?? null;

        if ($typeIdRaw === null || $typeIdRaw === '') {
            $project->setProjectType(null);
        } else {
            try {
                $typeId = Uuid::fromString((string) $typeIdRaw);
            } catch (\InvalidArgumentException) {
                throw new BadRequestHttpException('projectTypeId must be a UUID.');
            }
            $type = $this->types->find($typeId);
            if (!$type instanceof ProjectType || $type->getWorkspace() !== $project->getWorkspace()) {
                throw new BadRequestHttpException('ProjectType not found in this workspace.');
            }
            $project->setProjectType($type);
        }
        $this->em->flush();
        return new JsonResponse(['id' => $project->getId()?->toRfc4122(), 'projectTypeId' => $project->getProjectType()?->getId()?->toRfc4122()]);
    }

    #[Route(
        path: '/v1/projects/{id}/set-key',
        name: 'api_project_set_key',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function setKey(string $id, Request $request): JsonResponse
    {
        $project = $this->ownedProject($id, WorktidePermission::EDIT);
        $body = $this->body($request);
        $newKey = isset($body['projectKey']) ? strtoupper((string) $body['projectKey']) : '';
        if ($newKey === '' || !preg_match('/^[A-Z][A-Z0-9-]{0,15}$/', $newKey)) {
            throw new BadRequestHttpException('projectKey must be 1-16 chars: uppercase letters, digits, dashes; must start with a letter.');
        }
        $project->setKey($newKey);
        $this->em->flush();
        return new JsonResponse(['id' => $project->getId()?->toRfc4122(), 'projectKey' => $project->getKey()]);
    }

    #[Route(
        path: '/v1/projects/{id}/close',
        name: 'api_project_close',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function close(string $id): JsonResponse
    {
        $project = $this->ownedProject($id, WorktidePermission::EDIT);
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $project->close($user);
            $this->em->flush();
        }
        return new JsonResponse(['id' => $project->getId()?->toRfc4122(), 'closedOn' => $project->getClosedOn()?->format(\DateTimeInterface::ATOM)]);
    }

    #[Route(
        path: '/v1/projects/{id}/reopen',
        name: 'api_project_reopen',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function reopen(string $id): JsonResponse
    {
        $project = $this->ownedProject($id, WorktidePermission::EDIT);
        $project->reopen();
        $this->em->flush();
        return new JsonResponse(['id' => $project->getId()?->toRfc4122(), 'closedOn' => null]);
    }

    #[Route(
        path: '/v1/projects/by-key/{key}',
        name: 'api_project_by_key',
        host: 'api.worktide.ddev.site',
        requirements: ['key' => '[A-Z][A-Z0-9-]{0,15}'],
        methods: ['GET'],
    )]
    public function getByKey(string $key): JsonResponse
    {
        $project = $this->projects->findOneBy(['key' => strtoupper($key)]);
        if (!$project instanceof Project) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $project)) {
            throw new AccessDeniedHttpException();
        }
        return new JsonResponse([
            'id' => $project->getId()?->toRfc4122(),
            'key' => $project->getKey(),
            'name' => $project->getName(),
        ]);
    }

    private function ownedProject(string $id, string $permission): Project
    {
        $project = $this->projects->find(Uuid::fromString($id));
        if (!$project instanceof Project) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted($permission, $project)) {
            throw new AccessDeniedHttpException();
        }
        return $project;
    }

    private function extractUuid(Request $request, string $field): Uuid
    {
        $body = $this->body($request);
        $raw = $body[$field] ?? null;
        if (!\is_string($raw)) {
            throw new BadRequestHttpException("Field {$field} (UUID) is required.");
        }
        try {
            return Uuid::fromString($raw);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException("Field {$field} must be a UUID.");
        }
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
