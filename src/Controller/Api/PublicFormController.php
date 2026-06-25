<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\PublicFormRepository;
use App\Service\PublicFormSubmissionService;
use App\Service\PublicFormValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, unauthenticated form endpoints. The slug in the path is the only
 * credential, so both routes live behind PUBLIC_ACCESS (security.yaml,
 * `^/v1/forms/`). Authenticated admin CRUD for the form definitions lives
 * elsewhere, at `/v1/public_forms` (the PublicForm ApiResource).
 *
 *   GET  /v1/forms/{slug}  → public-safe form schema for a renderer
 *   POST /v1/forms/{slug}  → submit, creating a Task in the target project
 *
 * Like {@see WebhookIngestController}, unknown / disabled / soft-deleted forms
 * all return the same 404 so a slug can't be probed. The endpoints never echo
 * back any internal identifier (workspace, project, status, or the created
 * task) — an anonymous caller learns only success/failure.
 *
 * Abuse mitigation: a per-IP rate limiter plus a honeypot field (`_hp`). A
 * tripped honeypot returns the normal success shape without creating anything,
 * so a bot can't tell it was filtered.
 */
final class PublicFormController
{
    private const HONEYPOT_FIELD = '_hp';

    public function __construct(
        private readonly PublicFormRepository $forms,
        private readonly PublicFormSubmissionService $submissions,
        private readonly RateLimiterFactory $publicFormSubmitLimiter,
    ) {}

    #[Route(
        path: '/v1/forms/{slug}',
        name: 'api_public_form_schema',
        host: 'api.worktide.ddev.site',
        requirements: ['slug' => '[a-z0-9-]{1,60}'],
        methods: ['GET'],
    )]
    public function schema(string $slug): JsonResponse
    {
        $form = $this->forms->findOneEnabledBySlug($slug);
        if ($form === null) {
            throw new NotFoundHttpException();
        }

        return new JsonResponse([
            'slug' => $form->getSlug(),
            'title' => $form->getTitle(),
            'description' => $form->getDescription(),
            'successMessage' => $form->getSuccessMessage(),
            'fields' => array_map(
                static fn (array $f): array => [
                    'key' => $f['key'] ?? null,
                    'label' => $f['label'] ?? ($f['key'] ?? null),
                    'type' => $f['type'] ?? 'text',
                    'required' => (bool) ($f['required'] ?? false),
                    'options' => array_values((array) ($f['options'] ?? [])),
                    'placeholder' => $f['placeholder'] ?? null,
                ],
                $form->getFields(),
            ),
        ]);
    }

    #[Route(
        path: '/v1/forms/{slug}',
        name: 'api_public_form_submit',
        host: 'api.worktide.ddev.site',
        requirements: ['slug' => '[a-z0-9-]{1,60}'],
        methods: ['POST'],
    )]
    public function submit(string $slug, Request $request): JsonResponse
    {
        $form = $this->forms->findOneEnabledBySlug($slug);
        if ($form === null) {
            throw new NotFoundHttpException();
        }

        // Per-IP throttle before any work — keyed by client IP like the
        // auth-adjacent limiters in AuthRateLimitSubscriber.
        $limiter = $this->publicFormSubmitLimiter->create($request->getClientIp() ?? 'unknown');
        $reservation = $limiter->consume(1);
        if (!$reservation->isAccepted()) {
            $retryAfter = max(1, (int) ceil($reservation->getRetryAfter()->getTimestamp() - time()));
            throw new TooManyRequestsHttpException($retryAfter, sprintf('Too many submissions — retry in %ds.', $retryAfter));
        }

        $values = $this->decodeBody($request);

        // Honeypot: a real visitor never fills the hidden field. Return the
        // normal success shape so a bot can't distinguish a drop from a save.
        if (($values[self::HONEYPOT_FIELD] ?? '') !== '') {
            return $this->success($form);
        }

        $limit = $form->getSubmissionLimit();
        if ($limit !== null && $form->getSubmissionCount() >= $limit) {
            return new JsonResponse(['error' => 'This form is no longer accepting submissions.'], 409);
        }

        try {
            $this->submissions->submit(
                $form,
                $values,
                $request->getClientIp(),
                $request->headers->get('User-Agent'),
            );
        } catch (PublicFormValidationException $e) {
            return new JsonResponse(['errors' => $e->getErrors()], 422);
        }

        return $this->success($form);
    }

    /** @return array<string, mixed> */
    private function decodeBody(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function success(\App\Entity\PublicForm $form): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'message' => $form->getSuccessMessage(),
        ], 201);
    }
}
