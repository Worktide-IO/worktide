<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Opt-out marker: a {@see \App\Entity\Trait\SoftDeletableTrait} entity that must
 * still be HARD-deleted on API DELETE.
 *
 * Soft-delete keeps the row, which is wrong for link/pivot rows that get
 * re-created with the same unique key right after removal (unassign → reassign):
 * a lingering soft-deleted row would collide on the unique constraint (409)
 * until the retention purge. Such entities implement this so
 * {@see \App\State\SoftDeleteRemoveProcessorDecorator} skips soft-delete for them.
 */
interface HardDeleteOnly
{
}
