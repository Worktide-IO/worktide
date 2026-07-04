<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/** Channel a lead activity happened on (nullable for internal notes/stage changes). */
enum LeadActivityChannel: string
{
    case Email = 'email';
    case Forum = 'forum';
    case LinkedIn = 'linkedin';
    case Phone = 'phone';
    case Web = 'web';
}
