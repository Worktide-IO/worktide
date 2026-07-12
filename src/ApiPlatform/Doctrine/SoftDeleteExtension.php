<?php

declare(strict_types=1);

namespace App\ApiPlatform\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\File;
use App\Entity\Folder;
use Doctrine\ORM\QueryBuilder;

/**
 * Hides soft-deleted rows from API Platform reads for the file-manager entities.
 *
 * The app uses {@see \App\Entity\Trait\SoftDeletableTrait} widely but has no
 * global query filter, so soft-deleted rows otherwise still surface in
 * collections. For the Nextcloud-like file tree that's wrong: a recursively
 * deleted folder (and its files) must vanish. Scoped to File + Folder on purpose
 * to keep the blast radius tiny; broadening this to every SoftDeletable resource
 * is a sensible follow-up but out of scope here.
 */
final class SoftDeleteExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    private const CLASSES = [File::class, Folder::class];

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
