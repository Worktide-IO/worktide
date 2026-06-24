<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Granular capabilities used by the permission resolver.
 *
 * NEVER rename a case value once shipped — DB rows in role_permission_overrides
 * reference these strings verbatim, and external integrations may pin to
 * specific capabilities by name.
 *
 * The split between `*_own` and `*_others` is deliberate — many roles can
 * mutate their own tracked time but not their coworkers'. When a capability
 * makes no such distinction (e.g. project archive), only one case exists.
 */
enum Capability: string
{
    // --- Workspace ----------------------------------------------------
    case WorkspaceManageSettings = 'workspace.manage_settings';
    case WorkspaceManageMembers = 'workspace.manage_members';
    case WorkspaceManageBilling = 'workspace.manage_billing';

    // --- Project ------------------------------------------------------
    case ProjectCreate = 'project.create';
    case ProjectUpdate = 'project.update';
    case ProjectArchive = 'project.archive';
    case ProjectDelete = 'project.delete';
    case ProjectManageMembers = 'project.manage_members';

    // --- Task ---------------------------------------------------------
    case TaskCreate = 'task.create';
    case TaskUpdate = 'task.update';
    case TaskAssign = 'task.assign';
    case TaskDeleteOwn = 'task.delete_own';
    case TaskDeleteOthers = 'task.delete_others';

    // --- Time tracking ------------------------------------------------
    case TimeEntryCreate = 'time_entry.create';
    case TimeEntryUpdateOwn = 'time_entry.update_own';
    case TimeEntryUpdateOthers = 'time_entry.update_others';
    case TimeEntryDeleteOwn = 'time_entry.delete_own';
    case TimeEntryDeleteOthers = 'time_entry.delete_others';
    // Toggling the "billed" flag on one's OWN entries is split out from
    // update_own so a workspace can keep self-service time editing while
    // reserving the accounting-relevant billed status for finance/admins.
    case TimeEntryToggleBilledOwn = 'time_entry.toggle_billed_own';

    // --- Files & comments --------------------------------------------
    case FileUpload = 'file.upload';
    case FileDeleteOthers = 'file.delete_others';
    case CommentCreate = 'comment.create';
    case CommentDeleteOthers = 'comment.delete_others';

    // --- Documents ----------------------------------------------------
    case DocumentCreate = 'document.create';
    case DocumentDeleteOthers = 'document.delete_others';

    // --- Automation / integrations ------------------------------------
    case AutomationManage = 'automation.manage';
    case WebhookManage = 'webhook.manage';
    case ReportsView = 'reports.view';
}
