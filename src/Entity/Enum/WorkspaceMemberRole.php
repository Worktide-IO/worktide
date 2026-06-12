<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum WorkspaceMemberRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Guest = 'guest';
}
