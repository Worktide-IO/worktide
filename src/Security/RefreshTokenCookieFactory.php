<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Builds the httpOnly refresh-token cookie for auth flows that issue a JWT
 * directly (setup, invitation-accept) instead of going through the gesdinet
 * login/refresh success listener. Attributes MUST match
 * config/packages/gesdinet_jwt_refresh_token.yaml (cookie.*) so all paths write
 * an identical cookie the bundle can later read/rotate/clear.
 */
final class RefreshTokenCookieFactory
{
    /** gesdinet cookie name = its token_parameter_name (default). */
    public const NAME = 'refresh_token';

    public static function create(string $token, int $ttlSeconds): Cookie
    {
        return Cookie::create(
            self::NAME,
            $token,
            time() + $ttlSeconds,
            '/',
            null,   // host-only (same-site to the SPAs under the shared registrable domain)
            true,   // secure
            true,   // httpOnly
            false,  // raw
            Cookie::SAMESITE_LAX,
        );
    }
}
