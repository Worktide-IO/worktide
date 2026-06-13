<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware\UuidBinding;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Statement;

final class UuidBindingConnection extends AbstractConnectionMiddleware
{
    public function prepare(string $sql): Statement
    {
        return new UuidBindingStatement(parent::prepare($sql));
    }
}
