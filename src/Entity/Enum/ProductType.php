<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Whether a catalog item is a versioned product or a versionless service.
 * Products carry {@see \App\Entity\ProductVersion} releases; services do not.
 */
enum ProductType: string
{
    case Product = 'product';
    case Service = 'service';

    public function isVersioned(): bool
    {
        return $this === self::Product;
    }
}
