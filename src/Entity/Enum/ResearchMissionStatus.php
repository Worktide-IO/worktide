<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/** Lifecycle of a research mission. */
enum ResearchMissionStatus: string
{
    case Draft = 'draft';            // just created
    case Clarifying = 'clarifying';  // agent asked questions, awaiting answers
    case Ready = 'ready';            // brief complete, can run
    case Running = 'running';        // discovery in progress
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case Archived = 'archived';
}
