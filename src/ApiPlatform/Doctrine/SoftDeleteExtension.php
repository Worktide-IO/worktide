<?php

declare(strict_types=1);

namespace App\ApiPlatform\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Conversation;
use App\Entity\File;
use App\Entity\Folder;
use Doctrine\ORM\QueryBuilder;

/**
 * Hides soft-deleted rows from API Platform reads for the entities whose DELETE
 * is soft (via {@see \App\State\SoftDeleteProcessor}).
 *
 * The app uses {@see \App\Entity\Trait\SoftDeletableTrait} widely but has no
 * global query filter, so soft-deleted rows otherwise still surface in
 * collections. Enrolled per-entity (not globally) so read-hiding and the
 * soft-delete processor stay in lockstep: File + Folder (recursive file tree)
 * and Conversation (deleting a thread must not orphan its inbound/outbound
 * history). Broadening to every SoftDeletable resource is a sensible follow-up.
 */
final class SoftDeleteExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    private const CLASSES = [File::class, Folder::class, Conversation::class];

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->apply($queryBuilder, $resourceClass);
    }

    /**
     * @param array<string, mixed> $identifiers
     */
    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->apply($queryBuilder, $resourceClass);
    }

    private function apply(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (!\in_array($resourceClass, self::CLASSES, true)) {
            return;
        }
        $alias = $queryBuilder->getRootAliases()[0];
        $queryBuilder->andWhere(sprintf('%s.deletedAt IS NULL', $alias));
    }
}
