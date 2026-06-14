<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failure = 'failure';
}
