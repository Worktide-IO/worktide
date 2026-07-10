<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Invalidate refresh tokens for the current user.
 *
 * Request body (JSON), all optional:
 *   {}                                    ← current device (refresh token read
 *                                           from the httpOnly cookie)
 *   { "refresh_token": "..." }            ← drop a specific token
 *   { "everywhere": true }                ← sign out every device for this user
 *
 * Always expires the refresh-token cookie on the response, so a subsequent
 * silent-refresh-on-load can't revive the session. Idempotent: a logout with no
 * identifiable token still clears the cookie and returns { "revoked": 0 }.
 *
 * Trade-off: the currently-issued JWT keeps working until its natural expiry
 * (1 h by default). Clients drop it (it's in-memory only) — and we can layer a
 * JWT denylist (Redis) on top later if stricter immediate-revocation is needed.
 */
final class LogoutController
{
    /** gesdinet cookie name = its token_parameter_name (default). */
    private const COOKIE_NAME = 'refresh_token';

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/auth/logout',
        name: 'api_auth_logout',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }

        $payload = $this->decodeBody($request);
        $everywhere = (bool) ($payload['everywhere'] ?? false);
        // The refresh token now lives in an httpOnly cookie the SPA can't read;
        // fall back to it so a plain POST (empty body) logs out this device.
        $refreshToken = (isset($payload['refresh_token']) && \is_string($payload['refresh_token']) && $payload['refresh_token'] !== '')
            ? $payload['refresh_token']
            : $request->cookies->get(self::COOKIE_NAME);

        $repo = $this->em->getRepository(RefreshToken::class);

        $revoked = 0;
        if ($everywhere) {
            /** @var list<RefreshToken> $tokens */
            $tokens = $repo->findBy(['username' => $user->getUserIdentifier()]);
            foreach ($tokens as $t) {
                $this->em->remove($t);
                $revoked++;
            }
        } elseif (\is_string($refreshToken) && $refreshToken !== '') {
            $token = $repo->findOneBy([
                'refreshToken' => $refreshToken,
                'username' => $user->getUserIdentifier(),
            ]);
            if ($token !== null) {
                $this->em->remove($token);
                $revoked = 1;
            }
        }

        $this->em->flush();

        $response = new JsonResponse(['revoked' => $revoked]);
        // Expire the cookie (match attributes) so the session can't be revived.
        $response->headers->clearCookie(self::COOKIE_NAME, '/', null, true, true, 'lax');

        return $response;
    }

    /** @return array<string, mixed> */
    private function decodeBody(Request $request): array
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
        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('Body must be a JSON object.');
        }
        return $decoded;
    }
}
