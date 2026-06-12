<?php

declare(strict_types=1);

namespace App\ApiPlatform\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Automatically scopes every API Platform collection + item query to the
 * workspaces the authenticated user is a member of.
 *
 * This is the safety-net against cross-tenant data leaks: even if a developer
 * forgets to add an access voter on a custom operation, the underlying query
 * still won't return rows from other tenants.
 *
 * Behaviour:
 *  - Active for any resource using WorkspaceScopedTrait.
 *  - Adds an EXISTS subquery against workspace_members for the authed user.
 *  - X-Workspace-Id header narrows to a single workspace.
 *  - Anonymous requests get an impossible condition so they see nothing.
 *  - ROLE_SUPER_ADMIN bypasses scoping (for support/debug).
 *
 * Implementation note: we use a correlated EXISTS subquery binding only the
 * user-id (typed as UUID) — this side-steps Doctrine's ambiguity about how to
 * bind UUID values in IN() lists for FK columns stored as BINARY(16).
 */
final class WorkspaceScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {}

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->apply($queryBuilder, $queryNameGenerator, $resourceClass);
    }

    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->apply($queryBuilder, $queryNameGenerator, $resourceClass);
    }

    private function apply(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
    ): void {
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return;
        }

        $isWorkspaceItself = $resourceClass === Workspace::class;
        if (!$isWorkspaceItself && !$this->isWorkspaceScoped($resourceClass)) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            $queryBuilder->andWhere('1 = 0');
            return;
        }

        $userParam = $queryNameGenerator->generateParameterName('scopeUserId');
        $subAlias = $queryNameGenerator->generateJoinAlias('wmscope');

        // For Workspace itself: filter to workspaces the user is a member of.
        // For other resources: filter to entities whose .workspace the user is a member of.
        $wsExpr = $isWorkspaceItself ? $rootAlias : sprintf('%s.workspace', $rootAlias);

        $subDql = sprintf(
            'SELECT 1 FROM %s %s WHERE %s.workspace = %s AND %s.user = :%s',
            WorkspaceMember::class,
            $subAlias,
            $subAlias,
            $wsExpr,
            $subAlias,
            $userParam,
        );

        $queryBuilder
            ->andWhere(sprintf('EXISTS (%s)', $subDql))
            ->setParameter($userParam, $user->getId(), UuidType::NAME);

        // X-Workspace-Id narrowing — only meaningful for workspace-scoped resources
        // (Workspace itself is already filtered to membership).
        if ($isWorkspaceItself) {
            return;
        }

        $requested = $this->requestStack->getCurrentRequest()?->headers->get('X-Workspace-Id');
        if ($requested === null || $requested === '') {
            return;
        }
        try {
            $requestedUuid = Uuid::fromString($requested);
        } catch (\InvalidArgumentException) {
            $queryBuilder->andWhere('1 = 0');
            return;
        }

        $wsParam = $queryNameGenerator->generateParameterName('scopeWsId');
        $queryBuilder
            ->andWhere(sprintf('%s.workspace = :%s', $rootAlias, $wsParam))
            ->setParameter($wsParam, $requestedUuid, UuidType::NAME);
    }

    private function isWorkspaceScoped(string $resourceClass): bool
    {
        $traits = [];
        $class = $resourceClass;
        while ($class !== false) {
            $traits += class_uses($class) ?: [];
            $class = get_parent_class($class);
        }
        return isset($traits[WorkspaceScopedTrait::class]);
    }
}
