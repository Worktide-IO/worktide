<?php

declare(strict_types=1);

namespace App\Channels\OAuth;

/**
 * Thrown when an OAuth2 token-exchange or refresh fails — invalid
 * authorization code, expired refresh token, revoked consent, etc.
 *
 * The OAuth controller maps this to a 401 with a reconnect-CTA in
 * the SPA; adapters surface it as a "channel needs reconnect"
 * Channel.lastSyncError so the next poll skips it without spinning.
 */
final class OAuth2TokenException extends \RuntimeException
{
}
