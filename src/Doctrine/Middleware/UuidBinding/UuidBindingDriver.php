<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware\UuidBinding;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class UuidBindingDriver extends AbstractDriverMiddleware
{
    public function connect(array $params): Connection
    {
        return new UuidBindingConnection(parent::connect($params));
    }
}
