<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Progress state of a {@see \App\Entity\CustomerGoal}. Drives the portal
 * "Ziele" badges (unterwegs / erreicht / …). Progress % is derived from
 * current vs. target values; this is the qualitative status the agency sets.
 */
enum GoalStatus: string
{
    case OnTrack = 'on_track';
    case AtRisk = 'at_risk';
    case Reached = 'reached';
    case Missed = 'missed';
}
