<?php

declare(strict_types=1);

namespace App\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Facets a resource by arbitrary keys of its `attributes` JSON map.
 *
 * Usage:
 *   ?attr[Hersteller]=INTEWA                 attributes.Hersteller = "INTEWA"
 *   ?attr[Steuer][]=19%25&attr[Steuer][]=7%25   attributes.Steuer IN ("19%","7%")  (OR)
 *   ?attr[Hersteller]=X&attr[Steuer]=19%25       both must match                  (AND)
 *
 * Different keys AND together; multiple values for one key OR together. Backed
 * by the MySQL JSON_CONTAINS DQL function (see App\Doctrine\Dql\JsonContains).
 */
final class JsonAttributeFilter extends AbstractFilter
{
    /** JSON path key: letters/digits/space and a few separators, max 64. */
    private const string KEY_RE = '/^[\p{L}\p{N} _\-.]{1,64}$/u';

    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if ($property !== 'attr' || !\is_array($value)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];

        foreach ($value as $key => $rawValues) {
            if (!\is_string($key) || !preg_match(self::KEY_RE, $key)) {
                continue;
            }

            $values = array_values(array_filter(
                \is_array($rawValues) ? $rawValues : [$rawValues],
                static fn ($v) => \is_scalar($v) && (string) $v !== '',
            ));
            if ($values === []) {
                continue;
            }

            $pathParam = $queryNameGenerator->generateParameterName('attrPath');
            $queryBuilder->setParameter($pathParam, '$."' . str_replace('"', '', $key) . '"');

            $ors = [];
            foreach ($values as $v) {
                $valParam = $queryNameGenerator->generateParameterName('attrVal');
                $ors[] = sprintf('JSON_CONTAINS(%s.attributes, :%s, :%s) = 1', $alias, $valParam, $pathParam);
                $queryBuilder->setParameter(
                    $valParam,
                    json_encode((string) $v, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                );
            }

            $queryBuilder->andWhere('(' . implode(' OR ', $ors) . ')');
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function getDescription(string $resourceClass): array
    {
        return [
            'attr' => [
                'property' => 'attributes',
                'type' => 'string',
                'required' => false,
                'is_collection' => true,
                'description' => 'Facet by an attributes-map key, e.g. attr[Hersteller]=INTEWA (repeat for OR).',
            ],
        ];
    }
}
