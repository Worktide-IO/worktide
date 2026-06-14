<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Enum\Capability;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\RolePermissionOverrideRepository;
use App\Repository\WorkspaceMemberRepository;

/**
 * Decides whether a user holds a given Capability inside a Workspace.
 *
 * Resolution order:
 *   1. user has no membership in the workspace  → denied (no inherited grants)
 *   2. lookup workspace role
 *   3. owner short-circuits to GRANTED for every capability — by design,
 *      see {@see DefaultPermissions} comment.
 *   4. look for a RolePermissionOverride row for (workspace, role, capability)
 *      and return its `isGranted` if present
 *   5. fall back to the static DefaultPermissions matrix
 *
 * Voters call this for fine-grained checks like "can this user delete somebody
 * else's time entry"; the coarse VIEW/EDIT/DELETE/MANAGE attributes still flow
 * through the existing voter delegation chain unchanged.
 */
final class PermissionResolver
{
    public function __construct(
        private readonly WorkspaceMemberRepository $wsMembers,
        private readonly RolePermissionOverrideRepository $overrides,
    ) {}

    public function can(User $user, Capability $capability, ?Workspace $workspace): bool
    {
        if ($workspace === null) {
            return false;
        }

        $member = $this->wsMembers->findOneBy([
            'workspace' => $workspace,
            'user' => $user,
        ]);
        if ($member === null) {
            return false;
        }

        $role = $member->getRole();
        if ($role === WorkspaceMemberRole::Owner) {
            return true;
        }

        $override = $this->overrides->findOverride($workspace, $role, $capability);
        if ($override !== null) {
            return $override->isGranted();
        }
        return DefaultPermissions::isGrantedByDefault($role, $capability);
    }

    /**
     * Returns the full effective matrix for a workspace — convenient for
     * settings UIs that want to render a checkbox grid.
     *
     * @return array<string, array<string, bool>>  role-value → capability-value → granted
     */
    public function matrixFor(Workspace $workspace): array
    {
        $rows = [];
        $overrides = $this->overrides->findForWorkspace($workspace);
        $overrideIndex = [];
        foreach ($overrides as $o) {
            $overrideIndex[$o->getRole()->value][$o->getCapability()->value] = $o->isGranted();
        }

        foreach (WorkspaceMemberRole::cases() as $role) {
            $rows[$role->value] = [];
            foreach (Capability::cases() as $cap) {
                if ($role === WorkspaceMemberRole::Owner) {
                    $rows[$role->value][$cap->value] = true;
                    continue;
                }
                $rows[$role->value][$cap->value]
                    = $overrideIndex[$role->value][$cap->value]
                    ?? DefaultPermissions::isGrantedByDefault($role, $cap);
            }
        }
        return $rows;
    }
}
