<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/** Author of a research-mission clarification message. */
enum MissionMessageRole: string
{
    case Agent = 'agent';
    case User = 'user';
}
