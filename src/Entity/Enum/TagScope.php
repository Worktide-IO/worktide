<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum TagScope: string
{
    case Project = 'project';
    case Task = 'task';
    case Any = 'any';
}
