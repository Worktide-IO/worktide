<?php

declare(strict_types=1);

namespace App\Channels\OAuth;

use App\Entity\Channel;

/**
 * Resolves (client_id, client_secret, redirect_uri) for a given
 * channel + provider, walking the precedence order documented on
 * {@see OAuth2AppCredentials}.
 *
 * Per-channel override: Channel.outboundConfig.oauthApp = {clientId,
 * clientSecret}. Stored in the encrypted authConfig layer at-rest;
 * this resolver reads the already-decrypted value.
 *
 * Workspace default: future work. The hook is here so the resolver
 * doesn't need to be touched again when it lands.
 *
 * Worktide-global: env-backed `OAUTH_GRAPH_CLIENT_ID` +
 * `OAUTH_GRAPH_CLIENT_SECRET` for MS Graph, `OAUTH_GMAIL_*` for
 * Google. Throws if none of the three layers yields a credential —
 * the caller (OAuth controller / adapter) surfaces this as a
 * configuration error rather than crashing on an unauthenticated
 * provider call.
 */
final class OAuth2AppCredentialsResolver
{
    public function __construct(
        private readonly string $baseRedirectUri,
        private readonly ?string $graphClientId,
        private readonly ?string $graphClientSecret,
        private readonly ?string $gmailClientId,
        private readonly ?string $gmailClientSecret,
        private readonly ?string $linkedinClientId = null,
        private readonly ?string $linkedinClientSecret = null,
    ) {}

    public function resolveForChannel(Channel $channel): OAuth2AppCredentials
    {
        $code = $channel->getAdapterCode();
        $override = $channel->getAuthConfig()['oauthApp'] ?? null;
        if (is_array($override) && !empty($override['clientId']) && !empty($override['clientSecret'])) {
            return new OAuth2AppCredentials(
                clientId: (string) $override['clientId'],
                clientSecret: (string) $override['clientSecret'],
                redirectUri: $this->buildRedirectUri(),
                origin: 'channel',
            );
        }

        // (Future) per-workspace override would slot in here.

        $clientId = match ($code) {
            'email_graph' => $this->graphClientId,
            'email_gmail' => $this->gmailClientId,
            'social_linkedin' => $this->linkedinClientId,
            default => null,
        };
        $clientSecret = match ($code) {
            'email_graph' => $this->graphClientSecret,
            'email_gmail' => $this->gmailClientSecret,
            'social_linkedin' => $this->linkedinClientSecret,
            default => null,
        };
        // Env var stem for the operator-facing hint (OAUTH_<STEM>_CLIENT_ID).
        $envStem = match ($code) {
            'email_graph' => 'GRAPH',
            'email_gmail' => 'GMAIL',
            'social_linkedin' => 'LINKEDIN',
            default => strtoupper(str_replace(['email_', 'social_'], '', $code)),
        };
        if ($clientId === null || $clientId === '' || $clientSecret === null || $clientSecret === '') {
            throw new OAuth2ConfigurationException(sprintf(
                'No OAuth2 app credentials configured for adapter "%s". Set OAUTH_%s_CLIENT_ID + OAUTH_%s_CLIENT_SECRET, or paste a per-channel override.',
                $code,
                $envStem,
                $envStem,
            ));
        }
        return new OAuth2AppCredentials(
            clientId: $clientId,
            clientSecret: $clientSecret,
            redirectUri: $this->buildRedirectUri(),
            origin: 'global',
        );
    }

    private function buildRedirectUri(): string
    {
        return rtrim($this->baseRedirectUri, '/') . '/v1/channels/oauth/callback';
    }
}
