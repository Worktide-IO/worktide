<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Health signal carried by a {@see \App\Entity\ProjectStatusUpdate} — the RAG
 * status of a project at the moment the update was posted.
 *
 * Mirrors the vocabulary teams already know from Asana status updates so the
 * SPA can colour the badge without a translation layer.
 */
enum ProjectHealth: string
{
    case OnTrack = 'on_track';
    case AtRisk = 'at_risk';
    case OffTrack = 'off_track';
    case OnHold = 'on_hold';
    case Complete = 'complete';
}
