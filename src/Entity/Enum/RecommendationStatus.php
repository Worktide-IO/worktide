<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Lifecycle of an {@see \App\Entity\AIRecommendation} (human-in-the-loop).
 *
 * Pending    — awaiting human review
 * Accepted   — a reviewer applied it to the ticket
 * Rejected   — a reviewer dismissed it
 * Superseded — a newer triage run replaced this still-pending one
 */
enum RecommendationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
