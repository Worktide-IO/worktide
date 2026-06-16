<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Cross-project visibility of a Version (Redmine/bluemine-inspired).
 *
 *   none        — owning project only
 *   descendants — owning project + its sub-projects
 *   hierarchy   — owning project + ancestors + descendants (full tree slice)
 *   tree        — entire project tree from the root down
 *   system      — every workspace project (use for org-wide release trains)
 *
 * Worktide MVP only ships none + system (no sub-project hierarchy yet) —
 * the enum is shaped to grow into the full Redmine model later.
 */
enum VersionSharing: string
{
    case None = 'none';
    case Descendants = 'descendants';
    case Hierarchy = 'hierarchy';
    case Tree = 'tree';
    case System = 'system';
}
