<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What an Automation does when its trigger fires. The action's `config`
 * column is a free-form JSON object whose shape depends on the type —
 * e.g. set-task-status needs {"statusId": "<uuid>"}; add-tag needs
 * {"tagId": "<uuid>"}; post-comment needs {"content": "..."}.
 *
 * The shape is documented in ActionRunner where each branch reads it.
 */
enum AutomationActionType: string
{
    case SetTaskStatus = 'task.set_status';
    case SetTaskPriority = 'task.set_priority';
    case AddTaskTag = 'task.add_tag';
    case AssignTaskUser = 'task.assign_user';
    case PostTaskComment = 'task.post_comment';
    case CloseTask = 'task.close';
}
