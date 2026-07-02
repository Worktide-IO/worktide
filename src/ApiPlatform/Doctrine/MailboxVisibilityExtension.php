<?php

declare(strict_types=1);

namespace App\ApiPlatform\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Conversation;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\InboundEvent;
use App\Entity\OutboundMessage;
use App\Entity\User;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Restricts mailbox-derived resources (Conversation / InboundEvent /
 * OutboundMessage) by mailbox type, on top of the workspace scoping done by
 * {@see WorkspaceScopeExtension}.
 *
 * A row is visible when its channel is:
 *  - shared AND the user is an internal member (owner/admin/member — guests
 *    excluded), OR
 *  - a personal mailbox owned by the user (channel.ownerUser = me), OR
 *  - in a workspace where the user is owner/admin (admins see personal too).
 *
 * Safety-net for every collection + item query, so personal mailboxes never
 * leak into shared inbox lists even on custom operations.
 */
final class MailboxVisibilityExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    private const RESOURCES = [Conversation::class, InboundEvent::class, OutboundMessage::class];

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
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return;
        }
        if (!\in_array($resourceClass, self::RESOURCES, true)) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            $queryBuilder->andWhere('1 = 0');
            return;
        }

        $ch = $queryNameGenerator->generateJoinAlias('mbchannel');
        $shared = $queryNameGenerator->generateJoinAlias('mbshared');
        $admin = $queryNameGenerator->generateJoinAlias('mbadmin');
        $userParam = $queryNameGenerator->generateParameterName('mbUserId');
        $internalRolesParam = $queryNameGenerator->generateParameterName('mbInternalRoles');
        $adminRolesParam = $queryNameGenerator->generateParameterName('mbAdminRoles');

        $queryBuilder->innerJoin(sprintf('%s.channel', $rootAlias), $ch);

        $sharedExists = sprintf(
            'EXISTS (SELECT 1 FROM %s %s WHERE %s.workspace = %s.workspace AND %s.user = :%s AND %s.role IN (:%s))',
            WorkspaceMember::class, $shared, $shared, $ch, $shared, $userParam, $shared, $internalRolesParam,
        );
        $adminExists = sprintf(
            'EXISTS (SELECT 1 FROM %s %s WHERE %s.workspace = %s.workspace AND %s.user = :%s AND %s.role IN (:%s))',
            WorkspaceMember::class, $admin, $admin, $ch, $admin, $userParam, $admin, $adminRolesParam,
        );

        $queryBuilder
            ->andWhere(sprintf(
                '((%s.isShared = true AND %s) OR %s.ownerUser = :%s OR %s)',
                $ch, $sharedExists, $ch, $userParam, $adminExists,
            ))
            ->setParameter($userParam, $user->getId(), UuidType::NAME)
            ->setParameter($internalRolesParam, [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin, WorkspaceMemberRole::Member])
            ->setParameter($adminRolesParam, [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin]);
    }
}
