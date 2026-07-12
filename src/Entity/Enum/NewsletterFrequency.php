<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Estimated send cadence of a newsletter — a hint shown to subscribers to set
 * expectations, not an enforced schedule. The human label is localised via the
 * translator (`label.newsletter_frequency.<value>`), same pattern as BillingCycle.
 */
enum NewsletterFrequency: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
    case Irregular = 'irregular';
}
