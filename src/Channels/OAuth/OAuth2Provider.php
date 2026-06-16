<?php

declare(strict_types=1);

namespace App\Channels\OAuth;

/**
 * Static description of one OAuth2 provider Worktide can talk to.
 *
 * A provider is the *protocol* shape — endpoints, scopes, refresh
 * semantics. App credentials (`client_id`/`client_secret`) come from
 * a separate layer ({@see OAuth2AppCredentials}) so the same
 * provider definition can be re-used by:
 *
 *   - The Worktide-global app (default — env-backed credentials
 *     shared across every workspace).
 *   - Per-workspace overrides where the Workspace-Admin registered
 *     their own app at Microsoft / Google and pasted the client_id
 *     into the Channel.outboundConfig.
 *
 * The provider catalog is in {@see OAuth2ProviderRegistry}; new
 * providers register by adding one entry there.
 */
final class OAuth2Provider
{
    /**
     * @param list<string> $defaultScopes
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $authorizeUrl,
        public readonly string $tokenUrl,
        public readonly array $defaultScopes,
        public readonly bool $usesPkce = false,
        public readonly bool $offlineAccess = true,
    ) {}
}
