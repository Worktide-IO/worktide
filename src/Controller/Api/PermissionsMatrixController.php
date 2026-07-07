<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Security\PermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * GET /v1/permissions/matrix?workspace=<uuid>
 *
 * Returns the effective role × capability matrix for one workspace, with
 * overrides already applied on top of the static defaults. The caller has to
 * be a member of the workspace; permission-management UIs only need a
 * read-side endpoint since overrides themselves go through the standard
 * /v1/role_permission_overrides CRUD.
 */
final class PermissionsMatrixController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly PermissionResolver $resolver,
    ) {}

    #[Route(
        path: '/v1/permissions/matrix',
        name: 'api_permissions_matrix',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $workspace = $this->resolveWorkspace($request, $user);
        if ($workspace === null) {
            throw new BadRequestHttpException('workspace parameter or X-Workspace-Id header required.');
        }

        $isMember = $this->em->getRepository(WorkspaceMember::class)
            ->findOneBy(['workspace' => $workspace, 'user' => $user]) !== null;
        if (!$isMember) {
            throw new AccessDeniedHttpException('Not a member of this workspace.');
        }

        return new JsonResponse([
            'workspaceId' => $workspace->getId()?->toRfc4122(),
            'matrix' => $this->resolver->matrixFor($workspace),
        ]);
    }

    private function resolveWorkspace(Request $request, User $user): ?Workspace
    {
        $param = $request->query->get('workspace') ?? $request->headers->get('X-Workspace-Id');
        if (!\is_string($param) || $param === '') {
            $membership = $this->em->getRepository(WorkspaceMember::class)->findOneBy(['user' => $user]);
            return $membership?->getWorkspace();
        }
        // accept both IRI ("/v1/workspaces/<uuid>") and raw uuid
        if (\str_contains($param, '/')) {
            $param = \basename($param);
        }
        try {
            return $this->em->find(Workspace::class, Uuid::fromString($param));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
