<?php

declare(strict_types=1);

namespace App\ApiPlatform\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Channel;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Restricts the {@see Channel} collection/item by mailbox type, on top of the
 * workspace scoping done by {@see WorkspaceScopeExtension}. The sibling
 * {@see MailboxVisibilityExtension} does the same for mailbox-derived resources
 * (Conversation/InboundEvent/OutboundMessage); this one covers the Channel row
 * itself, so a colleague's personal mailbox never shows up in another member's
 * source list.
 *
 * A channel is visible when it is:
 *  - shared AND the user is an internal member (owner/admin/member — guests
 *    excluded), OR
 *  - a personal mailbox owned by the user (ownerUser = me), OR
 *  - in a workspace where the user is owner/admin (admins see personal too).
 */
final class ChannelVisibilityExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
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
        if ($resourceClass !== Channel::class) {
            return;
        }
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return;
        }

        $c = $queryBuilder->getRootAliases()[0];
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            $queryBuilder->andWhere('1 = 0');
            return;
        }

        $shared = $queryNameGenerator->generateJoinAlias('chshared');
        $admin = $queryNameGenerator->generateJoinAlias('chadmin');
        $userParam = $queryNameGenerator->generateParameterName('chUserId');
        $internalRolesParam = $queryNameGenerator->generateParameterName('chInternalRoles');
        $adminRolesParam = $queryNameGenerator->generateParameterName('chAdminRoles');

        $sharedExists = sprintf(
            'EXISTS (SELECT 1 FROM %s %s WHERE %s.workspace = %s.workspace AND %s.user = :%s AND %s.role IN (:%s))',
            WorkspaceMember::class, $shared, $shared, $c, $shared, $userParam, $shared, $internalRolesParam,
        );
        $adminExists = sprintf(
            'EXISTS (SELECT 1 FROM %s %s WHERE %s.workspace = %s.workspace AND %s.user = :%s AND %s.role IN (:%s))',
            WorkspaceMember::class, $admin, $admin, $c, $admin, $userParam, $admin, $adminRolesParam,
        );

        $queryBuilder
            ->andWhere(sprintf(
                '((%s.isShared = true AND %s) OR %s.ownerUser = :%s OR %s)',
                $c, $sharedExists, $c, $userParam, $adminExists,
            ))
            ->setParameter($userParam, $user->getId(), UuidType::NAME)
            ->setParameter($internalRolesParam, [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin, WorkspaceMemberRole::Member])
            ->setParameter($adminRolesParam, [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin]);
    }
}
