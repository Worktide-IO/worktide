<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware\UuidBinding;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\AbstractUid;

final class UuidBindingStatement extends AbstractStatementMiddleware
{
    private const RFC_UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        if ($value instanceof AbstractUid) {
            // Symfony Uid object → 16-byte binary blob.
            $value = $value->toBinary();
            $type = ParameterType::STRING;
        } elseif (
            $type === ParameterType::STRING
            && \is_string($value)
            && \strlen($value) === 36
            && preg_match(self::RFC_UUID_PATTERN, $value) === 1
        ) {
            // Plain 36-char RFC 4122 UUID string bound as a STRING — assume
            // it targets a BINARY(16) UUID column and pack accordingly. All
            // our UUID columns are binary; non-UUID columns can never carry
            // a value that looks like a 36-char hex-with-dashes string in
            // practice, so the false-positive risk is essentially zero.
            $value = (string) hex2bin(str_replace('-', '', $value));
        }

        parent::bindValue($param, $value, $type);
    }
}
