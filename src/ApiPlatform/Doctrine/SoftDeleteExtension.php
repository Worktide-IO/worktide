<?php

declare(strict_types=1);

namespace App\ApiPlatform\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Trait\SoftDeletableTrait;
use Doctrine\ORM\QueryBuilder;

/**
 * Hides soft-deleted rows from every API Platform read for any resource whose
 * entity uses {@see SoftDeletableTrait} — the read-side counterpart of the
 * global soft-delete on DELETE ({@see \App\State\SoftDeleteRemoveProcessorDecorator}).
 *
 * Trait-detected, not a hand-maintained whitelist, so a new SoftDeletable
 * resource is hidden-when-deleted by default. Only the API surface is filtered;
 * repositories (threading, backfills, the retention purge) still see deleted
 * rows on purpose. Entities that hard-delete never set deletedAt, so the added
 * `deletedAt IS NULL` predicate is a harmless no-op for them.
 */
final class SoftDeleteExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, bool> */
    private array $softDeletableCache = [];

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
        if (!$this->isSoftDeletable($resourceClass)) {
            return;
        }
        $alias = $queryBuilder->getRootAliases()[0];
        $queryBuilder->andWhere(sprintf('%s.deletedAt IS NULL', $alias));
    }

    /** @param class-string $class */
    private function isSoftDeletable(string $class): bool
    {
        return $this->softDeletableCache[$class] ??= (function () use ($class): bool {
            if (!class_exists($class)) {
                return false;
            }
            $traits = [];
            for ($c = $class; $c !== false; $c = get_parent_class($c)) {
                $traits += class_uses($c) ?: [];
            }

            return isset($traits[SoftDeletableTrait::class]);
        })();
    }
}
