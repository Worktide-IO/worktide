<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Skill discipline of a staff member ({@see \App\Entity\User::$discipline}) and
 * the discipline a task needs ({@see \App\Entity\Task::$requiredDiscipline}).
 *
 * Drives role-based offering of unassigned tickets (Phase 2) and lets the
 * AI scheduler reason about who fits a ticket. A small fixed set for now;
 * promote to a per-workspace entity if agencies need custom disciplines.
 */
enum Discipline: string
{
    case Developer = 'developer';
    case Designer = 'designer';
    case ProjectManager = 'pm';
    case Qa = 'qa';
    case Marketing = 'marketing';
    case Other = 'other';
}
