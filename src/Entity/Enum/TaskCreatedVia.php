<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum TaskCreatedVia: string
{
    case Created = 'created';
    case Import = 'import';
    case Email = 'email';
    case Form = 'form';
    case Automation = 'automation';
    case Api = 'api';
    case Recurring = 'recurring';
    case Template = 'template';
    case Portal = 'portal';
}
