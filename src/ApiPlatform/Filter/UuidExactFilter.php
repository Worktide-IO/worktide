<?php

declare(strict_types=1);

namespace App\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Exact-match filter for Symfony `uuid` (BINARY(16)) columns.
 *
 * The built-in SearchFilter compares against the raw string and never matches
 * a binary uuid column, so `?targetId=<uuid>` silently returned nothing. This
 * binds a real {@see Uuid} with the `uuid` DBAL type so Doctrine converts it to
 * binary before comparing.
 */
final class UuidExactFilter extends AbstractFilter
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
        if (
            !\is_string($value)
            || !$this->isPropertyEnabled($property, $resourceClass)
            || !$this->isPropertyMapped($property, $resourceClass)
            || !Uuid::isValid($value)
        ) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $parameter = $queryNameGenerator->generateParameterName($property);

        $queryBuilder
            ->andWhere(\sprintf('%s.%s = :%s', $alias, $property, $parameter))
            ->setParameter($parameter, Uuid::fromString($value), UuidType::NAME);
    }

    /**
     * @return array<string, array{property: string, type: string, required: bool, description: string}>
     */
    public function getDescription(string $resourceClass): array
    {
        $description = [];
        foreach (array_keys($this->properties ?? []) as $property) {
            $description[(string) $property] = [
                'property' => (string) $property,
                'type' => 'string',
                'required' => false,
                'description' => 'Exact match on a uuid property.',
            ];
        }

        return $description;
    }
}
