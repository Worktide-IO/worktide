<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Polymorphic comment-target. Extend as new commentable entities ship
 * (Document in B9, CRM Contact/Deal in Phase 3, …).
 */
enum CommentTarget: string
{
    case Project = 'project';
    case Task = 'task';
    case Document = 'document';
}
