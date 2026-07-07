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
 * Request body (JSON):
 *   { "refresh_token": "..." }            ← drop a single device
 *   { "everywhere": true }                ← sign out every device for this user
 *
 * Response: 200 with { "revoked": <int> } on success.
 *
 * Trade-off: the currently-issued JWT keeps working until its natural expiry
 * (1 h by default). Clients should drop it locally — and we can layer a JWT
 * denylist (Redis) on top later if/when stricter immediate-revocation is
 * required for compliance.
 */
final class LogoutController
{
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
        $refreshToken = isset($payload['refresh_token']) && \is_string($payload['refresh_token'])
            ? $payload['refresh_token']
            : null;
        $everywhere = (bool) ($payload['everywhere'] ?? false);

        if (!$everywhere && $refreshToken === null) {
            throw new BadRequestHttpException('Provide "refresh_token" or set "everywhere": true.');
        }

        $repo = $this->em->getRepository(RefreshToken::class);

        $revoked = 0;
        if ($everywhere) {
            /** @var list<RefreshToken> $tokens */
            $tokens = $repo->findBy(['username' => $user->getUserIdentifier()]);
            foreach ($tokens as $t) {
                $this->em->remove($t);
                $revoked++;
            }
        } else {
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

        return new JsonResponse(['revoked' => $revoked]);
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
