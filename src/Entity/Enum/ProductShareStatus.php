<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ProductShareStatus: string
{
    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
}
