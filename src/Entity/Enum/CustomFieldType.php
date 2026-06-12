<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum CustomFieldType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Number = 'number';
    case Date = 'date';
    case DateTime = 'date_time';
    case Boolean = 'boolean';
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Url = 'url';
    case Email = 'email';
    case User = 'user';
}
