<?php

declare(strict_types=1);

namespace App\Service\ExternalSearch;

use Symfony\Component\Uid\Uuid;

/**
 * A normalized query the research agent hands to every external-search provider.
 * `filters` carries provider-specific hints (e.g. tech=TYPO3, region=DACH,
 * industry=…, objective=…) that adapters use as they can and ignore otherwise.
 * `workspaceId` is the tenant scope the internal (own-database) provider needs;
 * the external web adapters ignore it.
 */
final readonly class ExternalSearchQuery
{
    /**
     * @param array<string, scalar> $filters
     */
    public function __construct(
        public string $query,
        public int $limit = 20,
        public array $filters = [],
        public ?Uuid $workspaceId = null,
    ) {}
}
