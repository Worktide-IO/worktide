<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Which entity types a custom field definition can be attached to.
 *
 * Add new targets here as Worktide grows (Contact, Company, Deal once CRM lands).
 */
enum CustomFieldTarget: string
{
    case Project = 'project';
    case Task = 'task';
    case TimeEntry = 'time_entry';
}
