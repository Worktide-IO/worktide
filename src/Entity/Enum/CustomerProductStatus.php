<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * State of a customer's assignment to a catalog product/service.
 */
enum CustomerProductStatus: string
{
    case Active = 'active';    // currently uses/owns it
    case Churned = 'churned';  // no longer
}
