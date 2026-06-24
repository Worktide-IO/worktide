<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\PasswordPolicy;
use App\Service\PasswordResetService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /v1/auth/reset-password — set a new password using a reset token.
 *
 * Body: { token, password }.
 *  - Password is checked against {@see PasswordPolicy} first → 422 with the
 *    same {violations, policy} shape as the change-password endpoint.
 *  - An unknown/expired/used token → 400 {"error":"invalid_or_expired"}
 *    (deliberately not distinguishing the cases).
 * Rate-limited in {@see \App\EventSubscriber\AuthRateLimitSubscriber}.
 */
final class ResetPasswordController
{
    public function __construct(
        private readonly PasswordResetService $resets,
        private readonly PasswordPolicy $passwordPolicy,
    ) {}

    #[Route(
        path: '/v1/auth/reset-password',
        name: 'api_auth_reset_password',
        host: 'api.worktide.ddev.site',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $token = \is_string($body['token'] ?? null) ? $body['token'] : null;
        $password = \is_string($body['password'] ?? null) ? $body['password'] : null;
        if ($token === null || $token === '' || $password === null || $password === '') {
            throw new BadRequestHttpException('token + password required.');
        }

        $violations = $this->passwordPolicy->violations($password);
        if ($violations !== []) {
            return new JsonResponse(
                [
                    'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                    'message' => 'New password does not meet the policy.',
                    'violations' => $violations,
                    'policy' => [
                        'minLength' => $this->passwordPolicy->minLength(),
                        'minClasses' => $this->passwordPolicy->minClasses(),
                    ],
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (!$this->resets->consume($token, $password)) {
            return new JsonResponse(['error' => 'invalid_or_expired'], Response::HTTP_BAD_REQUEST);
        }

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
