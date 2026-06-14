<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Issues a short-lived JWT for the Worktide Mercure hub.
 *
 * The hub itself runs in non-anonymous mode (see reference_worktide_mercure
 * memory) so subscribers must present an HS256-signed token whose `mercure.
 * subscribe` claim covers the topics they want to listen to.
 *
 * Auth contract: the *requesting* user has to be authenticated via the
 * normal JWT (Lexik) firewall; the Mercure token we emit here is a
 * separate, hub-only JWT signed with MERCURE_JWT_SECRET. The two never mix
 * — JWT for the API stays RSA-signed via Lexik's pem pair, the Mercure
 * JWT is HS256 against the shared secret the hub knows.
 *
 * Subscribe scope: we grant the holder rights to subscribe to ANY topic
 * under the API base URL (template `${API}/v1/{path*}`). The actual data
 * never flows through the SSE pipe — clients use the SSE frame only as a
 * "something changed" nudge and then refetch through the API where the
 * voter is the source of truth. Restricting topics further at this layer
 * adds friction without changing the security boundary.
 *
 * The `topic` claim is intentionally not pinned to a single workspace IRI
 * — that would force the SPA to re-fetch a token after every workspace
 * switch. We keep the token workspace-agnostic and let the voter handle
 * the per-tenant gating.
 */
final class MercureTokenController
{
    public function __construct(
        private readonly Security $security,
        private readonly HubInterface $hub,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/auth/mercure-token',
        name: 'api_auth_mercure_token',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function __invoke(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $factory = $this->hub->getFactory();
        if ($factory === null) {
            // Configuring the hub without a token factory means we never
            // wrote MERCURE_JWT_SECRET — surface that loudly instead of
            // returning a meaningless empty token.
            throw new \RuntimeException(
                'Mercure hub has no token factory; check MERCURE_JWT_SECRET configuration.',
            );
        }

        $expiresAt = new \DateTimeImmutable('+30 minutes');
        $jwt = $factory->create(
            subscribe: ['*'],
            publish: [],
            additionalClaims: [
                'exp' => $expiresAt,
                'sub' => $user->getId()?->toRfc4122(),
                'email' => $user->getEmail(),
            ],
        );

        // Pass the workspace memberships back so the SPA can show which
        // tenants the cookie is good for — also helps debugging when only
        // one workspace's live updates come through and you wonder why.
        $workspaceIds = [];
        foreach ($this->em->getRepository(WorkspaceMember::class)->findBy(['user' => $user]) as $m) {
            $id = $m->getWorkspace()->getId()?->toRfc4122();
            if ($id !== null) {
                $workspaceIds[] = $id;
            }
        }

        return new JsonResponse([
            'token' => $jwt,
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
            'workspaceIds' => $workspaceIds,
        ]);
    }
}
