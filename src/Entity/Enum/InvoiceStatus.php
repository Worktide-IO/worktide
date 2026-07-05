<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Payment state of a mirrored {@see \App\Entity\Invoice}. Maps the several
 * lexoffice voucherStatus values onto the three a customer cares about;
 * "overdue" is derived (open + past due date), not stored.
 */
enum InvoiceStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Voided = 'voided';

    /** Map a raw lexoffice voucherStatus onto our reduced set. */
    public static function fromLexoffice(string $raw): self
    {
        return match (strtolower(trim($raw))) {
            'paid', 'paidoff' => self::Paid,
            'voided' => self::Voided,
            default => self::Open,
        };
    }
}
