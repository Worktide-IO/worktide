<?php

declare(strict_types=1);

namespace App\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Adds a WHERE clause that includes products shared with a given workspace.
 * Usage: ?sharedWith=<workspace-uuid>
 *
 * Returns products that are either:
 *   - owned by the workspace (workspace_id = :ws), OR
 *   - shared with the workspace via an accepted ProductShare
 */
final class SharedWithFilter extends AbstractFilter
{
    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if ($property !== 'sharedWith' || !\is_string($value) || $value === '') {
            return;
        }

        $wsParam = $queryNameGenerator->generateParameterName('ws');
        $shareWsParam = $queryNameGenerator->generateParameterName('shareWs');

        $queryBuilder
            ->leftJoin('App\Entity\ProductShare', 'ps', 'WITH', 'ps.product = o.id AND ps.status = :' . $shareWsParam . 'Status')
            ->andWhere('o.workspace = :' . $wsParam . ' OR ps.targetWorkspace = :' . $shareWsParam)
            ->setParameter($wsParam, $value)
            ->setParameter($shareWsParam, $value)
            ->setParameter($shareWsParam . 'Status', 'accepted');
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'sharedWith' => [
                'property' => null,
                'type' => 'string',
                'required' => false,
                'description' => 'Include products shared with this workspace UUID.',
            ],
        ];
    }
}
