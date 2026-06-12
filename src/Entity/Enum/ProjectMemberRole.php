<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ProjectMemberRole: string
{
    case Manager = 'manager';
    case Contributor = 'contributor';
    case Viewer = 'viewer';
}
