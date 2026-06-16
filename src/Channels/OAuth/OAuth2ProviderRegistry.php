<?php

declare(strict_types=1);

namespace App\Channels\OAuth;

use App\Channels\UnknownAdapterException;

/**
 * Catalog of OAuth2 providers Worktide can drive.
 *
 * Keyed by the adapterCode of the Channel that uses them
 * (`email_graph` → Microsoft, `email_gmail` → Google) so the
 * OAuth controller can look up provider metadata from the same
 * code that's already in Channel.adapterCode.
 *
 * Static catalog for now — when third-party SSO / external CRM
 * channels land, a tagged-service variant of this registry takes
 * over without breaking callers.
 */
final class OAuth2ProviderRegistry
{
    /** @var array<string, OAuth2Provider> */
    private array $byCode;

    public function __construct()
    {
        $this->byCode = [
            'email_graph' => new OAuth2Provider(
                code: 'email_graph',
                label: 'Microsoft 365 / Exchange Online',
                authorizeUrl: 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
                tokenUrl: 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
                defaultScopes: [
                    'openid',
                    'profile',
                    'offline_access',
                    'https://graph.microsoft.com/Mail.Read',
                    'https://graph.microsoft.com/Mail.Send',
                    'https://graph.microsoft.com/Mail.ReadWrite',
                    'https://graph.microsoft.com/User.Read',
                ],
                offlineAccess: true,
            ),
            'email_gmail' => new OAuth2Provider(
                code: 'email_gmail',
                label: 'Google Workspace / Gmail',
                authorizeUrl: 'https://accounts.google.com/o/oauth2/v2/auth',
                tokenUrl: 'https://oauth2.googleapis.com/token',
                defaultScopes: [
                    'https://www.googleapis.com/auth/gmail.readonly',
                    'https://www.googleapis.com/auth/gmail.send',
                    'https://www.googleapis.com/auth/gmail.modify',
                    'https://www.googleapis.com/auth/userinfo.email',
                    'https://www.googleapis.com/auth/userinfo.profile',
                ],
                offlineAccess: true,
            ),
        ];
    }

    public function get(string $adapterCode): OAuth2Provider
    {
        return $this->byCode[$adapterCode]
            ?? throw new UnknownAdapterException("No OAuth2 provider registered for adapter '$adapterCode'.");
    }

    public function tryGet(string $adapterCode): ?OAuth2Provider
    {
        return $this->byCode[$adapterCode] ?? null;
    }
}
