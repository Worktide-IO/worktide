<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Project;
use App\Entity\ProjectTemplate;
use App\Entity\TaskBundle;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\ProjectTemplateRepository;
use App\Repository\TaskBundleRepository;
use App\Security\Voter\WorktidePermission;
use App\Service\ProjectTemplateInstantiator;
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
 * Template lifecycle endpoints:
 *
 *   POST /v1/project-templates/{id}/instantiate
 *        body: { name, projectKey, startsOn? }
 *        → creates a new Project (+ tasks from the bundle, dueDates relative
 *          to startsOn). Returns a short summary.
 *
 *   POST /v1/projects/{id}/save-as-template
 *        body: { name, includeTasks? = true }
 *        → captures the project's defaults as a new ProjectTemplate (+ a
 *          fresh TaskBundle from the current tasks when includeTasks).
 *
 *   POST /v1/projects/{id}/apply-bundle
 *        body: { bundleId, startsOn? }
 *        → adds the bundle's tasks to the existing project, dueDates relative
 *          to startsOn (or project.startsOn if omitted).
 */
final class TemplateActionsController
{
    public function __construct(
        private readonly Security $security,
        private readonly ProjectTemplateRepository $projectTemplates,
        private readonly ProjectRepository $projects,
        private readonly TaskBundleRepository $bundles,
        private readonly ProjectTemplateInstantiator $instantiator,
    ) {}

    #[Route(
        path: '/v1/project-templates/{id}/instantiate',
        name: 'api_project_template_instantiate',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function instantiate(string $id, Request $request): JsonResponse
    {
        $user = $this->actor();
        $template = $this->projectTemplates->find(Uuid::fromString($id));
        if (!$template instanceof ProjectTemplate) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $template->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }

        $body = $this->body($request);
        $name = $body['name'] ?? null;
        $projectKey = $body['projectKey'] ?? null;
        if (!\is_string($name) || $name === '' || !\is_string($projectKey) || $projectKey === '') {
            throw new BadRequestHttpException('Fields name and projectKey are required.');
        }

        $startsOn = $this->parseDate($body['startsOn'] ?? null);

        $result = $this->instantiator->instantiate($template, [
            'name' => $name,
            'projectKey' => strtoupper($projectKey),
            'startsOn' => $startsOn,
            'owner' => $user,
        ], $user);
        $project = $result['project'];

        return new JsonResponse([
            'id' => $project->getId()?->toRfc4122(),
            'key' => $project->getKey(),
            'name' => $project->getName(),
            'tasksCreated' => $result['tasksCreated'],
            'fromTemplate' => $template->getId()?->toRfc4122(),
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route(
        path: '/v1/projects/{id}/save-as-template',
        name: 'api_project_save_as_template',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function saveAsTemplate(string $id, Request $request): JsonResponse
    {
        $user = $this->actor();
        $project = $this->projects->find(Uuid::fromString($id));
        if (!$project instanceof Project) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $project)) {
            throw new AccessDeniedHttpException();
        }

        $body = $this->body($request);
        $name = $body['name'] ?? null;
        if (!\is_string($name) || $name === '') {
            throw new BadRequestHttpException('Field name required.');
        }
        $includeTasks = (bool) ($body['includeTasks'] ?? true);

        $result = $this->instantiator->saveAsTemplate($project, $name, $includeTasks, $user);
        $template = $result['template'];

        return new JsonResponse([
            'id' => $template->getId()?->toRfc4122(),
            'name' => $template->getName(),
            'taskBundleId' => $template->getTaskBundle()?->getId()?->toRfc4122(),
            'taskTemplatesCount' => $result['taskTemplatesCreated'],
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route(
        path: '/v1/projects/{id}/apply-bundle',
        name: 'api_project_apply_bundle',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function applyBundle(string $id, Request $request): JsonResponse
    {
        $user = $this->actor();
        $project = $this->projects->find(Uuid::fromString($id));
        if (!$project instanceof Project) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $project)) {
            throw new AccessDeniedHttpException();
        }

        $body = $this->body($request);
        $bundleIdRaw = $body['bundleId'] ?? null;
        if (!\is_string($bundleIdRaw)) {
            throw new BadRequestHttpException('Field bundleId required.');
        }
        try {
            $bundle = $this->bundles->find(Uuid::fromString($bundleIdRaw));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('bundleId must be a UUID.');
        }
        if (!$bundle instanceof TaskBundle) {
            throw new NotFoundHttpException('Bundle not found.');
        }

        $startsOn = $this->parseDate($body['startsOn'] ?? null) ?? $project->getStartsOn();

        $tasks = $this->instantiator->applyBundleToProject($bundle, $project, $startsOn, $user);
        // Service doesn't flush — controller does to keep transaction control here.
        $this->bundles->getEntityManager()->flush();

        return new JsonResponse([
            'projectId' => $project->getId()?->toRfc4122(),
            'bundleId' => $bundle->getId()?->toRfc4122(),
            'tasksCreated' => \count($tasks),
        ], JsonResponse::HTTP_CREATED);
    }

    private function actor(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }
        return $user;
    }

    private function parseDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!\is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new BadRequestHttpException('Invalid date format.');
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
