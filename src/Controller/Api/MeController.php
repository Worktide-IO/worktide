<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\WorkspaceMemberRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Returns the currently-authenticated user together with all workspaces
 * they are a member of and the active workspace (resolved from the
 * X-Workspace-Id request header if provided).
 *
 * UIs hit this once after login to know who they're talking to and which
 * workspaces to offer in a tenant switcher.
 */
final class MeController
{
    public function __construct(
        private readonly Security $security,
        private readonly WorkspaceMemberRepository $wsMembers,
    ) {}

    #[Route(
        path: '/v1/auth/me',
        name: 'api_auth_me',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }

        $activeId = $request->headers->get('X-Workspace-Id');
        $memberships = $this->wsMembers->findBy(['user' => $user]);

        $workspaces = [];
        foreach ($memberships as $m) {
            $ws = $m->getWorkspace();
            $workspaces[] = [
                'id' => $ws->getId()?->toRfc4122(),
                'name' => $ws->getName(),
                'slug' => $ws->getSlug(),
                'locale' => $ws->getLocale(),
                'timezone' => $ws->getTimezone(),
                'role' => $m->getRole()->value,
                'isCurrent' => $activeId !== null && $ws->getId()?->toRfc4122() === $activeId,
            ];
        }

        return new JsonResponse([
            'id' => $user->getId()?->toRfc4122(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'fullName' => $user->getFullName(),
            'roles' => $user->getRoles(),
            'lastLoginAt' => $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
            'workspaces' => $workspaces,
        ]);
    }
}
