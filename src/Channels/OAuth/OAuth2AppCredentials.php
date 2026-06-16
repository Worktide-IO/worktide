<?php

declare(strict_types=1);

namespace App\Channels\OAuth;

/**
 * The (client_id, client_secret) pair that authenticates the
 * Worktide-app to the provider — distinct from the per-user
 * (access_token, refresh_token) pair that authenticates the end-user
 * to the provider's API.
 *
 * Resolution order at runtime ({@see OAuth2AppCredentialsResolver}):
 *
 *   1. Per-channel override stored in Channel.outboundConfig[ 'oauthApp' ]
 *      → workspace-admin brought their own Azure-AD / Google-Cloud app.
 *   2. Per-workspace default stored in Workspace.settings (future) →
 *      organisation-wide app that every channel in the workspace uses.
 *   3. Worktide-global env-backed default — `OAUTH_GRAPH_CLIENT_ID`,
 *      `OAUTH_GMAIL_CLIENT_ID` — for the SaaS-mode "one click sign in
 *      with Microsoft" path.
 *
 * `redirectUri` is computed centrally so all three layers agree on
 * `https://{host}/v1/channels/oauth/callback`.
 */
final class OAuth2AppCredentials
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $redirectUri,
        /**
         * Where these credentials came from — useful for the audit log
         * and the SPA's "configured by: workspace / global" badge.
         */
        public readonly string $origin = 'global',
    ) {}
}
