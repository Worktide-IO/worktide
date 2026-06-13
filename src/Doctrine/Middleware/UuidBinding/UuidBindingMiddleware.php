<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware\UuidBinding;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL middleware that auto-converts Symfony Uid objects to their 16-byte
 * binary form when they are bound to a prepared statement WITHOUT an
 * explicit type.
 *
 * Why: when API Platform's SearchFilter (or any other code path) calls
 * `$qb->setParameter('foo', $uuidObject)` without `UuidType::NAME`, the
 * downstream DBAL binding calls `__toString()` and sends the 36-char
 * dashed string. MySQL's BINARY(16) FK columns then never match.
 *
 * This middleware intercepts `bindValue()` at the very last hop before the
 * SQL is executed, detects `AbstractUid` instances, and rewrites the value
 * to `$uid->toBinary()` so the comparison succeeds.
 *
 * Side effect: ALL routes that bind a Uuid now Just Work — not just the
 * API Platform filter. Doctrine ORM-managed columns are unaffected
 * (their UuidType already converts during the normal flow).
 */
final class UuidBindingMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new UuidBindingDriver($driver);
    }
}
