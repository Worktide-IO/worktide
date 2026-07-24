<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\HttpCache\HttpCache;

/**
 * Symfony reverse-proxy HTTP cache. Enabled opt-in via the HTTP_CACHE env
 * (see public/index.php) — off by default so prod behaviour is unchanged until
 * explicitly switched on. Backed by a filesystem store under
 * var/cache/<env>/http_cache.
 *
 * Safety: this only caches responses that are EXPLICITLY public+fresh
 * (Cache-Control: public, s-maxage=N). Authenticated API responses carry an
 * Authorization header and/or Cache-Control: private/no-cache, which HttpCache
 * never stores — so per-workspace, per-user data is never shared across tenants
 * via the cache. Only the deliberately-public endpoints (public booking pages,
 * public form render, public social embeds) opt in by emitting public headers.
 */
final class CacheKernel extends HttpCache
{
    protected function getOptions(): array
    {
        // NB: called from the parent constructor before $this->kernel exists,
        // so read the debug flag from the environment, not from getKernel().
        return [
            // Adds the X-Symfony-Cache response header so cache hits/misses are
            // observable in non-prod.
            'debug' => filter_var($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? false, \FILTER_VALIDATE_BOOL),
        ];
    }
}
