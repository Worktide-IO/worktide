<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/** Where a lead was discovered — maps 1:1 to the external-search adapters + manual entry. */
enum LeadSource: string
{
    case WebSearch = 'web_search';
    case Forum = 'forum';
    case LinkedIn = 'linkedin';
    case Directory = 'directory';
    case Referral = 'referral';
    case Manual = 'manual';
    case Import = 'import';
}
