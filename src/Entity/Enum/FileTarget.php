<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Where a file is attached. Polymorphic — extend as new file-bearing
 * entities ship (Document in B9, Company once CRM lands, etc.).
 */
enum FileTarget: string
{
    case Project = 'project';
    case Task = 'task';
    case Workspace = 'workspace';
    case User = 'user';
    case Comment = 'comment';
    case Document = 'document';
    case Customer = 'customer';
}
