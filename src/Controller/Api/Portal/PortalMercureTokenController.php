<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\User;
use App\Service\Portal\PortalAccessResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Issues a short-lived, tightly-scoped Mercure hub JWT to a portal user.
 *
 * Portal users hold ONLY ROLE_PORTAL, so they can't reach the staff
 * {@see \App\Controller\Api\MercureTokenController} (that route sits under the
 * `^/v1 → ROLE_USER` catch-all). This portal twin lives under `^/v1/portal`
 * so ROLE_PORTAL can fetch it.
 *
 * It is also deliberately TIGHTER than the staff token: where the staff token
 * grants `subscribe: ['*']`, this one pins the `mercure.subscribe` claim to the
 * portal user's OWN notifications topic. A customer contact has no business
 * receiving change-nudges for any other topic, and a narrow scope means an SSE
 * frame can't even leak the timing of unrelated staff/other-tenant activity.
 *
 * The notifications topic string is derived server-side from the authenticated
 * user and returned to the client (`topic`) so the SPA never builds the IRI
 * itself — it just subscribes to what it's handed.
 */
final class PortalMercureTokenController
{
    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly Security $security,
        private readonly HubInterface $hub,
    ) {}

    #[Route(
        path: '/v1/portal/mercure-token',
        name: 'api_portal_mercure_token',
        methods: ['GET'],
    )]
    public function __invoke(): JsonResponse
    {
        $this->portal->assertPortalEnabled();

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }

        $factory = $this->hub->getFactory();
        if ($factory === null) {
            // No token factory means MERCURE_JWT_SECRET was never wired — surface
            // that loudly instead of minting a meaningless empty token.
            throw new \RuntimeException(
                'Mercure hub has no token factory; check MERCURE_JWT_SECRET configuration.',
            );
        }

        $userId = $user->getId()?->toRfc4122();
        $topic = '/v1/users/' . $userId . '/notifications';

        $expiresAt = new \DateTimeImmutable('+30 minutes');
        $jwt = $factory->create(
            subscribe: [$topic],
            publish: [],
            additionalClaims: [
                'exp' => $expiresAt,
                'sub' => $userId,
            ],
        );

        return new JsonResponse([
            'token' => $jwt,
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
            'topic' => $topic,
        ]);
    }
}
