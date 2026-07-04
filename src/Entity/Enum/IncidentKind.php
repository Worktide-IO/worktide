<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Type of a {@see \App\Entity\SystemIncident} on a monitored CustomerSystem.
 * An OPEN (unresolved) incident drives the system's live status label.
 */
enum IncidentKind: string
{
    case Outage = 'outage';         // system unreachable → "Störung"
    case Degraded = 'degraded';     // reachable but slow/errors → "Langsam"
    case Maintenance = 'maintenance'; // planned window → "Wartung"
}
