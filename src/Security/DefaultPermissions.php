<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Enum\Capability;
use App\Entity\Enum\WorkspaceMemberRole;

/**
 * Static permission matrix: (WorkspaceMemberRole → Capability → bool).
 *
 * This is the BASELINE — every workspace inherits these grants on day one.
 * Operators tweak per-workspace behaviour by creating RolePermissionOverride
 * rows; the PermissionResolver consults overrides first and falls back here.
 *
 * Design rules:
 *   - Owner has EVERY capability — always. There must be exactly one role
 *     that can never lock itself out of its own workspace.
 *   - Admin can do everything except billing settings (separate finance role).
 *   - Member can collaborate (create, update, comment) but cannot delete
 *     others' work or manage members / settings.
 *   - Guest is read-mostly: can comment + create time entries (so external
 *     collaborators can log their hours), nothing destructive.
 *
 * Anything not explicitly listed here is denied for that role.
 */
final class DefaultPermissions
{
    /** @return list<Capability> */
    public static function grantedFor(WorkspaceMemberRole $role): array
    {
        return match ($role) {
            WorkspaceMemberRole::Owner => Capability::cases(),
            WorkspaceMemberRole::Admin => array_values(array_filter(
                Capability::cases(),
                static fn (Capability $c) => $c !== Capability::WorkspaceManageBilling,
            )),
            WorkspaceMemberRole::Member => [
                Capability::ProjectCreate,
                Capability::ProjectUpdate,
                Capability::ProjectArchive,
                Capability::TaskCreate,
                Capability::TaskUpdate,
                Capability::TaskAssign,
                Capability::TaskDeleteOwn,
                Capability::TimeEntryCreate,
                Capability::TimeEntryUpdateOwn,
                Capability::TimeEntryDeleteOwn,
                Capability::FileUpload,
                Capability::CommentCreate,
                Capability::DocumentCreate,
                Capability::ReportsView,
            ],
            WorkspaceMemberRole::Guest => [
                Capability::CommentCreate,
                Capability::TimeEntryCreate,
                Capability::TimeEntryUpdateOwn,
            ],
        };
    }

    public static function isGrantedByDefault(WorkspaceMemberRole $role, Capability $capability): bool
    {
        return \in_array($capability, self::grantedFor($role), true);
    }
}
