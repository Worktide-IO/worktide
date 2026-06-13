<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Classic project-management dependency types. The "lag" / "lead" delay
 * lives on the dependency itself (`lagMinutes`), not in the enum.
 *
 * FinishToStart is the default (most projects only ever need this).
 */
enum TaskDependencyType: string
{
    /** Successor can't start until predecessor finishes. (Default.) */
    case FinishToStart = 'finish_to_start';

    /** Successor can't start until predecessor starts. */
    case StartToStart = 'start_to_start';

    /** Successor can't finish until predecessor finishes. */
    case FinishToFinish = 'finish_to_finish';

    /** Successor can't finish until predecessor starts. (Rare.) */
    case StartToFinish = 'start_to_finish';
}
