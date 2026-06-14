<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * How often a ServiceSubscription bills. The `dateInterval()` helper turns
 * each case into a PHP DateInterval so nextBillingOn calculations have one
 * source of truth.
 *
 * `Once` is special — there is no "next billing"; the subscription stops
 * after the first cycle. Callers must check isRecurring() before computing
 * a next billing date.
 */
enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';
    case Once = 'once';

    public function isRecurring(): bool
    {
        return $this !== self::Once;
    }

    public function dateInterval(): \DateInterval
    {
        return match ($this) {
            self::Monthly => new \DateInterval('P1M'),
            self::Quarterly => new \DateInterval('P3M'),
            self::HalfYearly => new \DateInterval('P6M'),
            self::Yearly => new \DateInterval('P1Y'),
            self::Once => new \DateInterval('P0D'),
        };
    }

    /** Multiplier to derive an annualised price from one cycle's price. */
    public function annualMultiplier(): int
    {
        return match ($this) {
            self::Monthly => 12,
            self::Quarterly => 4,
            self::HalfYearly => 2,
            self::Yearly => 1,
            self::Once => 1,
        };
    }
}
