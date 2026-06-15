<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Polymorphic watch-target — what kinds of entities can a user "watch"?
 *
 * The list intentionally stays smaller than CommentTarget: watching a
 * customer or a system mostly mirrors watching their projects, and the
 * notification noise would not be worth it.
 *
 * New entries here ship together with their voter delegation rule —
 * `WatchVoter` only allows subscribing when the user can VIEW the target.
 */
enum WatchableTarget: string
{
    case Project = 'project';
    case Task = 'task';
    case Document = 'document';
}
