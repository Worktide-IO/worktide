<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Lifecycle of a Release/Version.
 *
 *   open   — actively planning + assigning tasks
 *   locked — scope frozen; only bug-fixes allowed, no new tasks
 *   closed — shipped; appears in changelog but no longer in active pickers
 */
enum VersionStatus: string
{
    case Open = 'open';
    case Locked = 'locked';
    case Closed = 'closed';
}
