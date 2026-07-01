<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware\UuidBinding;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\AbstractUid;

final class UuidBindingStatement extends AbstractStatementMiddleware
{
    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        if ($value instanceof AbstractUid) {
            // Symfony Uid object → 16-byte binary blob. This is the only safe,
            // type-driven conversion: the caller passed an actual Uid, so a
            // BINARY(16) UUID column is unambiguously the target.
            //
            // We deliberately do NOT sniff 36-char UUID-shaped *strings* here:
            // external systems (lexoffice, Google Calendar, …) legitimately
            // use RFC 4122 UUIDs as their record ids, and we store those in
            // VARCHAR columns (entity_syncs.external_id, discovered records).
            // Packing such a string to binary corrupts it (SQLSTATE 1366).
            // Pass a Uid object where a binary column is the target.
            $value = $value->toBinary();
            $type = ParameterType::STRING;
        }

        parent::bindValue($param, $value, $type);
    }
}
