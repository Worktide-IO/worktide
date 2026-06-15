<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Polymorphic principal type for task assignment.
 *
 * Mirrors Redmine's `Principal` STI hierarchy (User + Group). We avoid
 * Doctrine STI because PHP enum + UUID FK is simpler and the only
 * polymorphic axis we need is task-assignment — the rest of the app
 * keeps separate User and Team relationships.
 */
enum AssigneePrincipalType: string
{
    case User = 'user';
    case Team = 'team';
}
