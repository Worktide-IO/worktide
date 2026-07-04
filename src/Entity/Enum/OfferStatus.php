<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * State of a {@see \App\Entity\ProjectOffer} generated from an accepted
 * proposal. Open = issued/awaiting formalization; then Accepted or Declined.
 */
enum OfferStatus: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Declined = 'declined';
}
