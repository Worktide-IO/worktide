<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum CustomerStatus: string
{
    case Prospect = 'prospect';   // not yet a client — pipeline / lead
    case Active = 'active';        // has at least one running project or subscription
    case Inactive = 'inactive';    // no active engagement but kept on file
    case Churned = 'churned';      // formally ended — kept for invoicing history
    case Archived = 'archived';    // hidden from default lists
}
