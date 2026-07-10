<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Thrown by {@see OutboundUrlGuard} when a user-supplied outbound URL is not a
 * safe public http(s) target (bad scheme, unresolvable, or resolves to a
 * private / loopback / link-local / reserved address).
 */
final class UnsafeUrlException extends \RuntimeException
{
}
