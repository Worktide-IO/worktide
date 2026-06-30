<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Lifecycle of a single {@see \App\Entity\ProductVersion}. The newest released
 * version is `Current`; the {@see \App\Service\Catalog\ProductCatalogService}
 * demotes the previous current version to `Supported` when a new one ships.
 */
enum ProductVersionStatus: string
{
    case Current = 'current';
    case Supported = 'supported';
    case Deprecated = 'deprecated';
    case Eol = 'eol';
}
