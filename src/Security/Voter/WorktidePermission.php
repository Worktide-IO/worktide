<?php

declare(strict_types=1);

namespace App\Security\Voter;

/**
 * Canonical permission strings used by all Worktide voters.
 *
 * Kept as a final class with const values (instead of enum) so they can be
 * referenced directly in API Platform `security:` expressions:
 *   security: "is_granted('VIEW', object)"
 */
final class WorktidePermission
{
    public const VIEW = 'VIEW';
    public const EDIT = 'EDIT';
    public const DELETE = 'DELETE';
    public const MANAGE = 'MANAGE';

    public const ALL = [self::VIEW, self::EDIT, self::DELETE, self::MANAGE];

    private function __construct() {}
}
