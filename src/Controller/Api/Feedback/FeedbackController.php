<?php

declare(strict_types=1);

namespace App\Controller\Api\Feedback;

use App\Repository\WorkspaceRepository;
use App\Service\Feedback\FeedbackAnonymizer;
use App\Service\Feedback\FeedbackService;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use App\Entity\User;
use App\Entity\Workspace;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Staff-side endpoints for the shared, cross-tenant feedback board (any
 * ROLE_USER, under the ^/v1 firewall). A "ticket" is a Task in the feedback
 * project; all responses are anonymized DTOs from {@see FeedbackService}.
 */
final class FeedbackController
{
    public function __construct(
        private readonly FeedbackService $feedback,
        private readonly FeedbackAnonymizer $anonymizer,
        private readonly FileStorage $storage,
        private readonly Security $security,
        private readonly WorkspaceRepository $workspaces,
        private readonly EntityManagerInterface $em,
        private readonly RateLimiterFactory $feedbackSubmitLimiter,
        private readonly RateLimiterFactory $feedbackReplyLimiter,
    ) {}

    #[Route(path: '/v1/feedback', name: 'api_feedback_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return new JsonResponse([
            'items' => $this->feedback->board(
                $this->str($request->query->get('category')),
                $this->str($request->query->get('status')),
            ),
        ]);
    }

    #[Route(path: '/v1/feedback', name: 'api_feedback_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
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
            submitterUser: $user,
            submitterContact: null,
            originWorkspace: $this->workspaceFromHeader($request),
            sourceApp: 'staff',
            route: $this->str($body['route'] ?? null),
            appVersion: $this->str($body['appVersion'] ?? null),
            userAgent: $request->headers->get('User-Agent'),
            diagnostics: \is_array($body['diagnostics'] ?? null) ? $body['diagnostics'] : null,
        );

        return new JsonResponse($dto, 201);
    }

    #[Route(path: '/v1/feedback/{id}', name: 'api_feedback_show', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return new JsonResponse($this->feedback->detail(Uuid::fromString($id)));
    }

    #[Route(path: '/v1/feedback/{id}/replies', name: 'api_feedback_reply', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function reply(string $id, Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->throttle($this->feedbackReplyLimiter, $user);

        $body = $this->body($request);
        $content = \is_string($body['content'] ?? null) ? trim($body['content']) : '';
        if ($content === '') {
            throw new BadRequestHttpException('content required.');
        }

        return new JsonResponse($this->feedback->reply(Uuid::fromString($id), $content, $user), 201);
    }

    #[Route(path: '/v1/feedback/{id}/attachments', name: 'api_feedback_attach', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function attach(string $id, Request $request): JsonResponse
    {
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

    /**
     * Stream the admin-only screenshot for a ticket — Worktide team only
     * (platform-workspace members / super-admins). Never exposed on the
     * anonymized board.
     */
    #[Route(path: '/v1/feedback/{id}/screenshot', name: 'api_feedback_screenshot', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function screenshot(string $id): Response
    {
        if (!$this->anonymizer->isViewerPlatformAdmin()) {
            throw new AccessDeniedHttpException('Feedback screenshots are visible to the Worktide team only.');
        }

        $file = $this->feedback->screenshotFile(Uuid::fromString($id));
        $version = $file?->getCurrentVersion();
        if ($file === null || $version === null) {
            throw new NotFoundHttpException('No screenshot for this ticket.');
        }

        $response = new StreamedResponse(function () use ($version): void {
            $stream = $this->storage->readStream($version);
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            try {
                stream_copy_to_stream($stream, $out);
            } finally {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
                fclose($out);
            }
        });
        $response->headers->set('Content-Type', $version->getMimeType());
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $version->getOriginalFilename()),
        );

        return $response;
    }

    private function workspaceFromHeader(Request $request): ?Workspace
    {
        $raw = $request->headers->get('X-Workspace-Id');
        if (!\is_string($raw) || !Uuid::isValid($raw)) {
            return null;
        }

        return $this->workspaces->find(Uuid::fromString($raw));
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
