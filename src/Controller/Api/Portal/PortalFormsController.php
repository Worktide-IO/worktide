<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\Project;
use App\Entity\PublicForm;
use App\Repository\PublicFormRepository;
use App\Service\Portal\PortalAccessResolver;
use App\Service\PublicFormSubmissionService;
use App\Service\PublicFormValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Customer-portal questionnaires (wireframe screen 8, "SEO-Fragebogen").
 *
 * Reuses the existing {@see PublicForm} model — the same forms the agency
 * defines — but exposed to authenticated portal contacts scoped to their
 * customer's external projects (instead of the anonymous slug route). A
 * submission runs through {@see PublicFormSubmissionService}, which validates,
 * coerces, and materializes the audit Task in the form's project.
 *
 * SCOPE: one-shot submit. Draft-save / resume-across-sessions and true multi-
 * section state are NOT in the PublicForm model (no draft/contact fields) —
 * fields are grouped by an optional `section` key for display only. Persisted
 * drafts are a modelling follow-up. Gated by the `forms` feature flag.
 */
final class PortalFormsController
{
    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly PublicFormRepository $forms,
        private readonly PublicFormSubmissionService $submissions,
    ) {}

    #[Route(
        path: '/v1/portal/forms',
        name: 'api_portal_forms_list',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function list(): JsonResponse
    {
        $this->portal->assertFeatureEnabled('forms');

        $forms = $this->forms->findEnabledForPortalProjects($this->portal->allowedProjects());

        return new JsonResponse([
            'forms' => array_map(static fn (PublicForm $f): array => [
                'id' => $f->getId()?->toRfc4122(),
                'title' => $f->getTitle(),
                'description' => $f->getDescription(),
                'fieldCount' => \count($f->getFields()),
            ], $forms),
        ]);
    }

    #[Route(
        path: '/v1/portal/forms/{id}',
        name: 'api_portal_forms_show',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function show(string $id): JsonResponse
    {
        $this->portal->assertFeatureEnabled('forms');

        $form = $this->findFormOr404($id);

        // Public-safe field shape (mirrors PublicFormController::schema) plus an
        // optional `section` for grouped display. NEVER exposes `mapsTo`.
        return new JsonResponse([
            'id' => $form->getId()?->toRfc4122(),
            'title' => $form->getTitle(),
            'description' => $form->getDescription(),
            'successMessage' => $form->getSuccessMessage(),
            'fields' => array_map(static fn (array $f): array => [
                'key' => $f['key'] ?? null,
                'label' => $f['label'] ?? ($f['key'] ?? null),
                'type' => $f['type'] ?? 'text',
                'required' => (bool) ($f['required'] ?? false),
                'options' => array_values((array) ($f['options'] ?? [])),
                'placeholder' => $f['placeholder'] ?? null,
                'section' => $f['section'] ?? null,
            ], $form->getFields()),
        ]);
    }

    #[Route(
        path: '/v1/portal/forms/{id}/submit',
        name: 'api_portal_forms_submit',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function submit(string $id, Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('forms');

        $form = $this->findFormOr404($id);

        $limit = $form->getSubmissionLimit();
        if ($limit !== null && $form->getSubmissionCount() >= $limit) {
            return new JsonResponse(['error' => 'Dieser Fragebogen nimmt keine Antworten mehr an.'], 409);
        }

        $values = $this->body($request);
        try {
            $this->submissions->submit($form, $values, $request->getClientIp(), $request->headers->get('User-Agent'));
        } catch (PublicFormValidationException $e) {
            return new JsonResponse(['errors' => $e->getErrors()], 422);
        }

        return new JsonResponse(['success' => true, 'message' => $form->getSuccessMessage()], 201);
    }

    private function findFormOr404(string $id): PublicForm
    {
        $form = $this->forms->find(Uuid::fromString($id));
        if (
            !$form instanceof PublicForm
            || $form->getDeletedAt() !== null
            || !$form->isEnabled()
            || !$this->isAllowedProject($form->getProject())
        ) {
            throw new NotFoundHttpException('Form not found.');
        }
        return $form;
    }

    private function isAllowedProject(Project $project): bool
    {
        foreach ($this->portal->allowedProjects() as $allowed) {
            if ($allowed->getId()?->equals($project->getId()) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $raw = $request->getContent();
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Body must be valid JSON.');
        }
        return \is_array($decoded) ? $decoded : [];
    }
}
