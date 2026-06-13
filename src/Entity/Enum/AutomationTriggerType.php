<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Which domain event causes an Automation to fire.
 *
 * Add new triggers here as more entity changes warrant automation hooks
 * (TimeEntryCreated, MilestoneReached, FileUploaded …).
 */
enum AutomationTriggerType: string
{
    case TaskCreated = 'task.created';
    case TaskUpdated = 'task.updated';
    case TaskStatusChanged = 'task.status_changed';
    case TaskAssigneeChanged = 'task.assignee_changed';
    case TaskClosed = 'task.closed';
    case ProjectStatusChanged = 'project.status_changed';
    case ProjectClosed = 'project.closed';
}
