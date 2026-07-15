<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Portal\MagicLinkService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * POST /v1/auth/magic-link/consume — spend a passwordless portal login token
 * and mint a portal session.
 *
 * Body: { token }.
 *  - Unknown/expired/used token → 400 {"error":"invalid_or_expired"}.
 *  - Target portal access switched off since issue → 403 {"error":"portal_disabled"}.
 *  - Success → 200 { token: <JWT>, impersonation, customerName, issuedBy }.
 *    Issues NO refresh cookie — the preview session is ephemeral (1h JWT).
 *
 * PUBLIC (the token IS the credential). Rate-limited in
 * {@see \App\EventSubscriber\AuthRateLimitSubscriber}.
 */
final class PortalMagicLinkController
{
    public function __construct(
        private readonly MagicLinkService $magicLink,
    ) {}

    #[Route(
        path: '/v1/auth/magic-link/consume',
        name: 'api_auth_magic_link_consume',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $token = \is_string($body['token'] ?? null) ? $body['token'] : null;
        if ($token === null || $token === '') {
            throw new BadRequestHttpException('token required.');
        }

        try {
            $result = $this->magicLink->consume($token);
        } catch (CustomUserMessageAccountStatusException) {
            return new JsonResponse(['error' => 'portal_disabled'], Response::HTTP_FORBIDDEN);
        }

        if ($result === null) {
            return new JsonResponse(['error' => 'invalid_or_expired'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($result);
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
