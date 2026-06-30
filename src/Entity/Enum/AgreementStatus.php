<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * State of a customer agreement — shared by the {@see \App\Entity\CustomerAgreement}
 * head (effective state, maintained automatically) and each
 * {@see \App\Entity\CustomerAgreementRevision} (the state of that one version).
 *
 * Head vs. revision usage:
 *   - `None`          head only — no agreement on file for this type.
 *   - `Draft`         a version drafted but not yet under negotiation.
 *   - `InNegotiation` "in Abstimmung" — a version being negotiated.
 *   - `Signed`        a binding, in-force version (the head's currentRevision).
 *   - `Expired`       head only, time-derived — a signed version whose
 *                     validUntil has passed (flipped by the expiry command).
 *   - `Superseded`    revision only — a previously signed version replaced by
 *                     a newer signed one.
 *   - `Terminated`    the agreement was cancelled.
 */
enum AgreementStatus: string
{
    case None = 'none';
    case Draft = 'draft';
    case InNegotiation = 'in_negotiation';
    case Signed = 'signed';
    case Expired = 'expired';
    case Superseded = 'superseded';
    case Terminated = 'terminated';

    /** Currently binding (signed and not expired/terminated). */
    public function isEffective(): bool
    {
        return $this === self::Signed;
    }

    /** A version still open/in progress (drives the head's pendingRevision). */
    public function isPending(): bool
    {
        return $this === self::Draft || $this === self::InNegotiation;
    }

    /** Counts as "ever concluded" for the overview, regardless of expiry. */
    public function isConcluded(): bool
    {
        return \in_array($this, [self::Signed, self::Expired, self::Superseded, self::Terminated], true);
    }
}
