<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\PasswordResetService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /v1/auth/forgot-password — request a password-reset link by email.
 *
 * Always responds 200 {"ok":true}, whether or not an account exists, so the
 * endpoint can't be used to enumerate registered emails. Rate-limited in
 * {@see \App\EventSubscriber\AuthRateLimitSubscriber}.
 */
final class ForgotPasswordController
{
    public function __construct(
        private readonly PasswordResetService $resets,
    ) {}

    #[Route(
        path: '/v1/auth/forgot-password',
        name: 'api_auth_forgot_password',
        host: 'api.worktide.ddev.site',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $email = \is_string($body['email'] ?? null) ? $body['email'] : null;
        if ($email === null || trim($email) === '') {
            throw new BadRequestHttpException('email required.');
        }

        $this->resets->request($email);

        // Constant response regardless of account existence.
        return new JsonResponse(['ok' => true]);
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
            throw new BadRequestHttpException('Body must be JSON.');
        }
        return \is_array($decoded) ? $decoded : [];
    }
}
