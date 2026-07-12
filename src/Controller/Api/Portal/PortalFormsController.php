<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\PortalFormDraft;
use App\Entity\Project;
use App\Entity\PublicForm;
use App\Repository\PortalFormDraftRepository;
use App\Repository\PublicFormRepository;
use App\Service\Form\FormPrefillResolver;
use App\Service\Form\FormSchemaNormalizer;
use App\Service\Portal\PortalAccessResolver;
use App\Service\PublicFormSubmissionClosedException;
use App\Service\PublicFormSubmissionService;
use App\Service\PublicFormValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly PortalFormDraftRepository $drafts,
        private readonly EntityManagerInterface $em,
        private readonly FormSchemaNormalizer $normalizer,
        private readonly FormPrefillResolver $prefill,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route(
        path: '/v1/portal/forms',
        name: 'api_portal_forms_list',
        methods: ['GET'],
    )]
    public function list(): JsonResponse
    {
        $this->portal->assertFeatureEnabled('forms');

        $forms = $this->forms->findEnabledForPortalProjects($this->portal->allowedProjects());

        return new JsonResponse([
            'forms' => array_map(fn (PublicForm $f): array => [
                'id' => $f->getId()?->toRfc4122(),
                'title' => $f->getTitle(),
                'description' => $f->getDescription(),
                'translations' => $f->getTranslations(),
                'fieldCount' => \count($this->normalizer->inputBlocks($this->normalizer->normalize($f))),
            ], $forms),
        ]);
    }

    #[Route(
        path: '/v1/portal/forms/{id}',
        name: 'api_portal_forms_show',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function show(string $id): JsonResponse
    {
        $this->portal->assertFeatureEnabled('forms');

        $form = $this->findFormOr404($id);
        $draft = $this->drafts->findOneForContact($form, $this->portal->contact());
        $doc = $this->normalizer->normalize($form);

        // `schema` is the v2 engine document (pages/blocks/logic/calc) for the
        // Tally-like renderer; `fields` is the legacy flat list kept for
        // back-compat. Both are client-safe — NEVER expose `mapsTo`/`prefillFrom`.
        return new JsonResponse([
            'id' => $form->getId()?->toRfc4122(),
            'title' => $form->getTitle(),
            'description' => $form->getDescription(),
            'successMessage' => $form->getSuccessMessage(),
            // Per-locale title/description/successMessage overrides (see localize()).
            'translations' => $form->getTranslations(),
            'schema' => $this->normalizer->toClientSchema($doc),
            'fields' => $this->normalizer->toClientFields($doc),
            // Resume support: the contact's saved partial answers, if any.
            'draft' => $draft?->getAnswers(),
            'draftSavedAt' => $draft?->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route(
        path: '/v1/portal/forms/{id}/draft',
        name: 'api_portal_forms_save_draft',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['PUT'],
    )]
    public function saveDraft(string $id, Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('forms');

        $form = $this->findFormOr404($id);
        $contact = $this->portal->contact();

        $draft = $this->drafts->findOneForContact($form, $contact)
            ?? (new PortalFormDraft())->setForm($form)->setContact($contact);
        $draft->setAnswers($this->body($request));
        $this->em->persist($draft);
        $this->em->flush();

        return new JsonResponse(['savedAt' => $draft->getUpdatedAt()?->format(\DateTimeInterface::ATOM)]);
    }

    #[Route(
        path: '/v1/portal/forms/{id}/submit',
        name: 'api_portal_forms_submit',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function submit(string $id, Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('forms');

        $form = $this->findFormOr404($id);

        $limit = $form->getSubmissionLimit();
        if ($limit !== null && $form->getSubmissionCount() >= $limit) {
            return new JsonResponse(['error' => $this->translator->trans('label.error.form_closed')], 409);
        }

        $values = $this->body($request);

        // Hidden/prefill fields are filled server-side from the signed-in
        // contact + the form's project, never from the request body.
        $prefill = $this->prefill->resolve(
            $this->normalizer->normalize($form),
            $this->portal->contact(),
            $form->getProject(),
        );

        try {
            $this->submissions->submit($form, $values, $request->getClientIp(), $request->headers->get('User-Agent'), $prefill);
        } catch (PublicFormValidationException $e) {
            return new JsonResponse(['errors' => $e->getErrors()], 422);
        } catch (PublicFormSubmissionClosedException) {
            return new JsonResponse(['error' => $this->translator->trans('label.error.form_closed')], 409);
        }

        // Submitted → discard any saved draft for this contact.
        $draft = $this->drafts->findOneForContact($form, $this->portal->contact());
        if ($draft !== null) {
            $this->em->remove($draft);
            $this->em->flush();
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
