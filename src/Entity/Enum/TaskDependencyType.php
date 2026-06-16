<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Relation types between two Tasks.
 *
 * Two semantic families coexist on the same column:
 *   - Scheduling dependencies (PM-style, Gantt-relevant) — they imply a
 *     hard ordering, the cycle validator rejects loops, and `lagMinutes`
 *     is meaningful.
 *   - Issue-tracker relationships (Redmine/Jira-style) — informational
 *     links between tasks (duplicates, relates, see-also). No cycle
 *     check, no scheduling impact.
 *
 * `FinishToStart` is the default — most projects only ever need that.
 */
enum TaskDependencyType: string
{
    // --- Scheduling (Gantt-relevant, cycle-checked) -----------------

    /** Successor can't start until predecessor finishes. (Default.) */
    case FinishToStart = 'finish_to_start';

    /** Successor can't start until predecessor starts. */
    case StartToStart = 'start_to_start';

    /** Successor can't finish until predecessor finishes. */
    case FinishToFinish = 'finish_to_finish';

    /** Successor can't finish until predecessor starts. (Rare.) */
    case StartToFinish = 'start_to_finish';

    /**
     * Generic "this task blocks that one" — issue-tracker analog of
     * FinishToStart. Cycle-checked. Pick this when the relation is
     * about issues rather than a Gantt schedule.
     */
    case Blocks = 'blocks';

    /**
     * Predecessor must come before successor in time. Cycle-checked.
     * Useful for reading order / migration order without implying
     * scheduling math.
     */
    case Precedes = 'precedes';

    // --- Issue-tracker relations (informational, no cycle check) -----

    /** Successor is a duplicate of predecessor. */
    case Duplicates = 'duplicates';

    /** Generic "see also" — no semantics beyond the link itself. */
    case Relates = 'relates';

    /**
     * Successor comes after predecessor (workflow-wise) without any
     * blocking relationship. Inverse view of `Precedes` when the
     * caller doesn't care about schedule.
     */
    case Follows = 'follows';

    /**
     * Whether this relation type implies a hard ordering and therefore
     * a cycle would be a bug. The NoDependencyCycle validator skips
     * relations that return false here.
     */
    public function requiresAcyclic(): bool
    {
        return match ($this) {
            self::FinishToStart,
            self::StartToStart,
            self::FinishToFinish,
            self::StartToFinish,
            self::Blocks,
            self::Precedes => true,

            self::Duplicates,
            self::Relates,
            self::Follows => false,
        };
    }

    /**
     * Whether this relation blocks the successor's status from moving
     * forward while the predecessor is still open. Used by the UI to
     * decide whether to render the "Blockiert" badge on the kanban card.
     */
    public function isBlocking(): bool
    {
        return match ($this) {
            self::FinishToStart,
            self::Blocks,
            self::Precedes => true,

            self::StartToStart,
            self::FinishToFinish,
            self::StartToFinish,
            self::Duplicates,
            self::Relates,
            self::Follows => false,
        };
    }
}
