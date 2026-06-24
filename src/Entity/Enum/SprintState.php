<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Lifecycle of a Sprint (time-boxed iteration).
 *
 *   planned   — being filled from the backlog; not started yet
 *   active    — currently running (between start and end date)
 *   completed — finished; its velocity is final and feeds the chart
 */
enum SprintState: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Completed = 'completed';
}
