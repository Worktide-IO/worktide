<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\User;
use App\Service\Feedback\FeedbackService;
use App\Service\Portal\PortalAccessResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Portal-client endpoints for the shared feedback board (ROLE_PORTAL, gated by
 * the per-workspace `feedback` feature flag the agency admin controls). Same
 * anonymized DTOs as the staff controller — a portal client sees the global
 * board exactly as staff do, minus identities.
 */
final class PortalFeedbackController
{
    public function __construct(
        private readonly FeedbackService $feedback,
        private readonly PortalAccessResolver $portal,
        private readonly Security $security,
        private readonly RateLimiterFactory $feedbackSubmitLimiter,
        private readonly RateLimiterFactory $feedbackReplyLimiter,
    ) {}

    #[Route(path: '/v1/portal/feedback', name: 'api_portal_feedback_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('feedback');

        return new JsonResponse([
            'items' => $this->feedback->board(
                $this->str($request->query->get('category')),
                $this->str($request->query->get('status')),
            ),
        ]);
    }

    #[Route(path: '/v1/portal/feedback', name: 'api_portal_feedback_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('feedback');
        $user = $this->currentUser();
        $this->throttle($this->feedbackSubmitLimiter, $user);

        $body = $this->body($request);
        $title = \is_string($body['title'] ?? null) ? trim($body['title']) : '';
        if ($title === '') {
            throw new BadRequestHttpException('title required.');
        }

        $dto = $this->feedback->submit(
            title: $title,
            description: \is_string($body['description'] ?? null) ? $body['description'] : null,
            categoryKey: $this->str($body['category'] ?? null),
            createdBy: $user,
            submitterUser: null,
            submitterContact: $this->portal->contact(),
            originWorkspace: $this->portal->workspace(),
            sourceApp: 'portal',
            route: $this->str($body['route'] ?? null),
            appVersion: $this->str($body['appVersion'] ?? null),
            userAgent: $request->headers->get('User-Agent'),
            diagnostics: \is_array($body['diagnostics'] ?? null) ? $body['diagnostics'] : null,
        );

        return new JsonResponse($dto, 201);
    }

    #[Route(path: '/v1/portal/feedback/{id}', name: 'api_portal_feedback_show', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $this->portal->assertFeatureEnabled('feedback');

        return new JsonResponse($this->feedback->detail(Uuid::fromString($id)));
    }

    #[Route(path: '/v1/portal/feedback/{id}/replies', name: 'api_portal_feedback_reply', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function reply(string $id, Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('feedback');
        $user = $this->currentUser();
        $this->throttle($this->feedbackReplyLimiter, $user);

        $body = $this->body($request);
        $content = \is_string($body['content'] ?? null) ? trim($body['content']) : '';
        if ($content === '') {
            throw new BadRequestHttpException('content required.');
        }

        return new JsonResponse($this->feedback->reply(Uuid::fromString($id), $content, $user), 201);
    }

    #[Route(path: '/v1/portal/feedback/{id}/attachments', name: 'api_portal_feedback_attach', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function attach(string $id, Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('feedback');
        $user = $this->currentUser();

        $upload = $request->files->get('file');
        if (!$upload instanceof UploadedFile) {
            throw new BadRequestHttpException('Multipart field "file" is required.');
        }
        if ($upload->getSize() !== null && $upload->getSize() > FeedbackService::MAX_SCREENSHOT_BYTES) {
            throw new BadRequestHttpException('File is too large (max 10 MB).');
        }

        $this->feedback->attachScreenshot(Uuid::fromString($id), $upload, $user);

        return new JsonResponse(null, 204);
    }

    private function throttle(RateLimiterFactory $limiter, User $user): void
    {
        $limit = $limiter->create($user->getId()?->toRfc4122() ?? 'unknown');
        $reservation = $limit->consume();
        if (!$reservation->isAccepted()) {
            $retryAfter = max(1, $reservation->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retryAfter, sprintf('Too many requests — retry in %ds.', $retryAfter));
        }
    }

    private function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }

        return $user;
    }

    private function str(mixed $value): ?string
    {
        $value = \is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
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
