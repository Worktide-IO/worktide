<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum SubscriptionStatus: string
{
    case Trial = 'trial';         // free / discounted evaluation window
    case Active = 'active';        // billing as configured
    case Paused = 'paused';        // billing temporarily suspended (kept in records)
    case Cancelled = 'cancelled';  // ended — endedOn is set, no further billing
}
