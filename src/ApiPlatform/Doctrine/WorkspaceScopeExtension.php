<?php

declare(strict_types=1);

namespace App\ApiPlatform\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\DomainEventLog;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\ProjectShare;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Entity\User;
use App\Entity\UserCapacity;
use App\Entity\UserContactInfo;
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
        $isUser = $resourceClass === User::class;
        $isMembership = $resourceClass === WorkspaceMember::class;
        // Per-user rows scoped to the caller themselves (no cross-user reads).
        $isSelfOwned = $resourceClass === UserContactInfo::class || $resourceClass === UserCapacity::class;
        // ProjectMember scopes through its parent project's workspace.
        $isProjectMember = $resourceClass === ProjectMember::class;
        // DomainEventLog has a plain (nullable) .workspace → generic branch below.
        // Workspace / User / WorkspaceMember / the above have no
        // WorkspaceScopedTrait, so they get bespoke handling; everything else is
        // scoped only if it carries the trait.
        if (!$isWorkspaceItself && !$isUser && !$isMembership && !$isSelfOwned
            && !$isProjectMember && $resourceClass !== DomainEventLog::class
            && !$this->isWorkspaceScoped($resourceClass)) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            $queryBuilder->andWhere('1 = 0');
            return;
        }

        $userParam = $queryNameGenerator->generateParameterName('scopeUserId');

        // UserContactInfo / UserCapacity: per-user PII/capacity rows, visible
        // only to their owner. Simple self-scope; no cross-user reads at all.
        if ($isSelfOwned) {
            $queryBuilder
                ->andWhere(sprintf('%s.user = :%s', $rootAlias, $userParam))
                ->setParameter($userParam, $user->getId(), UuidType::NAME);
            return;
        }

        // ProjectMember: scope via the parent project's workspace — a row is
        // visible iff the caller is a member of the workspace that owns the
        // project. Prevents enumerating/tampering with other tenants' project
        // memberships (which grant task access via TaskVoter).
        if ($isProjectMember) {
            $wmAlias = $queryNameGenerator->generateJoinAlias('wmpm');
            $projAlias = $queryNameGenerator->generateJoinAlias('projpm');
            $subDql = sprintf(
                'SELECT 1 FROM %s %s, %s %s WHERE %s = %s.project AND %s.workspace = %s.workspace AND %s.user = :%s AND %s.isActive = true',
                WorkspaceMember::class, $wmAlias,
                Project::class, $projAlias,
                $projAlias, $rootAlias,
                $wmAlias, $projAlias,
                $wmAlias, $userParam,
                $wmAlias,
            );
            $queryBuilder
                ->andWhere(sprintf('EXISTS (%s)', $subDql))
                ->setParameter($userParam, $user->getId(), UuidType::NAME);

            $requested = $this->requestStack->getCurrentRequest()?->headers->get('X-Workspace-Id');
            if ($requested !== null && $requested !== '') {
                try {
                    $requestedUuid = Uuid::fromString($requested);
                } catch (\InvalidArgumentException) {
                    $queryBuilder->andWhere('1 = 0');
                    return;
                }
                $wsParam = $queryNameGenerator->generateParameterName('scopeWsId');
                $projNarrow = $queryNameGenerator->generateJoinAlias('projnarrow');
                $queryBuilder
                    ->andWhere(sprintf(
                        'EXISTS (SELECT 1 FROM %s %s WHERE %s = %s.project AND %s.workspace = :%s)',
                        Project::class, $projNarrow, $projNarrow, $rootAlias, $projNarrow, $wsParam,
                    ))
                    ->setParameter($wsParam, $requestedUuid, UuidType::NAME);
            }
            return;
        }

        // User is scoped by CO-MEMBERSHIP: a user row is visible iff it shares
        // at least one workspace with the caller. Without this, GET /v1/users
        // enumerates every tenant's users (names, emails).
        if ($isUser) {
            $selfAlias = $queryNameGenerator->generateJoinAlias('wmself');
            $callerAlias = $queryNameGenerator->generateJoinAlias('wmcaller');
            $coMembership = sprintf(
                'SELECT 1 FROM %s %s, %s %s WHERE %s.user = %s AND %s.workspace = %s.workspace AND %s.user = :%s AND %s.isActive = true',
                WorkspaceMember::class, $selfAlias,
                WorkspaceMember::class, $callerAlias,
                $selfAlias, $rootAlias,
                $selfAlias, $callerAlias,
                $callerAlias, $userParam,
                $callerAlias,
            );
            $queryBuilder
                ->andWhere(sprintf('EXISTS (%s)', $coMembership))
                ->setParameter($userParam, $user->getId(), UuidType::NAME);

            // X-Workspace-Id narrows to members of that single workspace.
            $requested = $this->requestStack->getCurrentRequest()?->headers->get('X-Workspace-Id');
            if ($requested !== null && $requested !== '') {
                try {
                    $requestedUuid = Uuid::fromString($requested);
                } catch (\InvalidArgumentException) {
                    $queryBuilder->andWhere('1 = 0');
                    return;
                }
                $wsParam = $queryNameGenerator->generateParameterName('scopeWsId');
                $narrowAlias = $queryNameGenerator->generateJoinAlias('wmnarrow');
                $queryBuilder
                    ->andWhere(sprintf(
                        'EXISTS (SELECT 1 FROM %s %s WHERE %s.user = %s AND %s.workspace = :%s)',
                        WorkspaceMember::class, $narrowAlias, $narrowAlias, $rootAlias, $narrowAlias, $wsParam,
                    ))
                    ->setParameter($wsParam, $requestedUuid, UuidType::NAME);
            }
            return;
        }

        $subAlias = $queryNameGenerator->generateJoinAlias('wmscope');

        // For Workspace itself: filter to workspaces the user is a member of.
        // For other resources: filter to entities whose .workspace the user is a member of.
        $wsExpr = $isWorkspaceItself ? $rootAlias : sprintf('%s.workspace', $rootAlias);

        $memberDql = sprintf(
            'SELECT 1 FROM %s %s WHERE %s.workspace = %s AND %s.user = :%s AND %s.isActive = true',
            WorkspaceMember::class,
            $subAlias,
            $subAlias,
            $wsExpr,
            $subAlias,
            $userParam,
            $subAlias,
        );

        // Cross-workspace project sharing: Project / Task / TimeEntry are also
        // visible to members of a workspace the owning project is shared INTO
        // (an accepted ProjectShare), in addition to the host-workspace members.
        $shareProjectExpr = $isWorkspaceItself ? null : $this->shareProjectExpr($resourceClass, $rootAlias);
        if ($shareProjectExpr !== null) {
            $psAlias = $queryNameGenerator->generateJoinAlias('psscope');
            $wmShareAlias = $queryNameGenerator->generateJoinAlias('wmshare');
            $shareDql = sprintf(
                'SELECT 1 FROM %s %s, %s %s WHERE %s.project = %s AND %s.sharedWithWorkspace = %s.workspace AND %s.user = :%s AND %s.isActive = true',
                ProjectShare::class, $psAlias,
                WorkspaceMember::class, $wmShareAlias,
                $psAlias, $shareProjectExpr,
                $psAlias, $wmShareAlias,
                $wmShareAlias, $userParam,
                $wmShareAlias,
            );
            $queryBuilder->andWhere(sprintf('(EXISTS (%s) OR EXISTS (%s))', $memberDql, $shareDql));
        } else {
            $queryBuilder->andWhere(sprintf('EXISTS (%s)', $memberDql));
        }
        $queryBuilder->setParameter($userParam, $user->getId(), UuidType::NAME);

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
        if ($shareProjectExpr !== null) {
            // The narrowed workspace may be the host workspace OR one the project
            // is shared into — otherwise a B-member with X-Workspace-Id=B would
            // never see the shared (workspace-A) rows.
            $psNarrow = $queryNameGenerator->generateJoinAlias('psnarrow');
            $queryBuilder->andWhere(sprintf(
                '(%s.workspace = :%s OR EXISTS (SELECT 1 FROM %s %s WHERE %s.project = %s AND %s.sharedWithWorkspace = :%s))',
                $rootAlias, $wsParam,
                ProjectShare::class, $psNarrow,
                $psNarrow, $shareProjectExpr,
                $psNarrow, $wsParam,
            ));
        } else {
            $queryBuilder->andWhere(sprintf('%s.workspace = :%s', $rootAlias, $wsParam));
        }
        $queryBuilder->setParameter($wsParam, $requestedUuid, UuidType::NAME);
    }

    /**
     * How to reach the shared Project from the query root, for the classes that
     * participate in cross-workspace sharing. Null = the resource is not shared
     * (only host-workspace membership applies). Comment is intentionally omitted
     * for now (polymorph targetId + the hidden-for-connect-users flag need their
     * own handling — a follow-up; until then shared workspaces simply don't see
     * host comments, which under-shares rather than leaks).
     */
    private function shareProjectExpr(string $resourceClass, string $rootAlias): ?string
    {
        return match ($resourceClass) {
            Project::class => $rootAlias,
            \App\Entity\Task::class, \App\Entity\TimeEntry::class => sprintf('%s.project', $rootAlias),
            default => null,
        };
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
