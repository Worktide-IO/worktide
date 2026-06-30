<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Lifecycle of a catalog product/service itself (not a single version).
 */
enum ProductStatus: string
{
    case Active = 'active';          // sold / offered
    case Deprecated = 'deprecated';  // still supported, not actively sold
    case Eol = 'eol';                // end of life
}
