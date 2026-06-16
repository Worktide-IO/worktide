<?php

declare(strict_types=1);

namespace App\Channels\OAuth;

use App\Entity\Channel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stateless helper around the OAuth2 dance + token-storage
 * convention on {@see Channel::$authConfig}.
 *
 * Token storage shape (after exchangeCode/refresh):
 *
 *   authConfig = {
 *     oauthApp?: { clientId, clientSecret },    // per-channel override
 *     accessToken: string,
 *     refreshToken: string,
 *     expiresAt: ISO8601,
 *     scope: string,
 *     tokenType: 'Bearer',
 *     ...adapter-specific (username, ...)
 *   }
 *
 * The Channel cipher listener (C.2) transparently encrypts each
 * leaf; this client reads/writes plain values.
 *
 * The `state` parameter the controller sends to the provider is a
 * signed JWT-like blob `<channelId>.<nonce>.<hmac>` so we can
 * resume to the right channel after the redirect without keeping
 * server-side session state. HMAC binds the channel ID to a nonce
 * we don't need to remember.
 */
final class OAuth2Client
{
    private const TOKEN_REFRESH_SLACK_SECONDS = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OAuth2AppCredentialsResolver $credentialsResolver,
        private readonly OAuth2ProviderRegistry $providers,
        private readonly EntityManagerInterface $em,
        private readonly string $stateSecret,
    ) {}

    /**
     * Build the URL the SPA redirects to so the user grants consent.
     * Includes a signed `state` so the callback knows which channel
     * is being authorised.
     */
    public function buildAuthorizeUrl(Channel $channel): string
    {
        $provider = $this->providers->get($channel->getAdapterCode());
        $creds = $this->credentialsResolver->resolveForChannel($channel);
        $params = [
            'client_id' => $creds->clientId,
            'redirect_uri' => $creds->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $provider->defaultScopes),
            'state' => $this->encodeState($channel->getId()?->toRfc4122() ?? ''),
        ];
        if ($provider->offlineAccess) {
            // Microsoft uses 'offline_access' as a scope; Google needs
            // 'access_type=offline' + 'prompt=consent' to reliably
            // return a refresh token even on re-consent.
            if ($channel->getAdapterCode() === 'email_gmail') {
                $params['access_type'] = 'offline';
                $params['prompt'] = 'consent';
            }
        }
        return $provider->authorizeUrl . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorisation code from the callback for an
     * access/refresh-token pair. Persists the tokens onto the
     * Channel in-place and flushes.
     */
    public function exchangeCode(Channel $channel, string $code): void
    {
        $provider = $this->providers->get($channel->getAdapterCode());
        $creds = $this->credentialsResolver->resolveForChannel($channel);

        $payload = $this->postTokenEndpoint($provider->tokenUrl, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $creds->redirectUri,
            'client_id' => $creds->clientId,
            'client_secret' => $creds->clientSecret,
        ]);
        $this->writeTokens($channel, $payload);
        $this->em->flush();
    }

    /**
     * Refresh the access token using the stored refresh token. No-op
     * when the current token still has more than 60 s left.
     *
     * Returns the access token suitable for an `Authorization: Bearer`
     * header — callers don't have to read it off the entity.
     */
    public function ensureAccessToken(Channel $channel): string
    {
        $auth = $channel->getAuthConfig();
        $accessToken = (string) ($auth['accessToken'] ?? '');
        $expiresAt = isset($auth['expiresAt']) ? new \DateTimeImmutable((string) $auth['expiresAt']) : null;

        // Token still fresh enough? Skip the refresh round-trip.
        if ($accessToken !== '' && $expiresAt !== null) {
            $secondsLeft = $expiresAt->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
            if ($secondsLeft > self::TOKEN_REFRESH_SLACK_SECONDS) {
                return $accessToken;
            }
        }

        $refreshToken = (string) ($auth['refreshToken'] ?? '');
        if ($refreshToken === '') {
            throw new OAuth2TokenException('No refresh token stored for this channel — user must reconnect.');
        }
        $provider = $this->providers->get($channel->getAdapterCode());
        $creds = $this->credentialsResolver->resolveForChannel($channel);
        $payload = $this->postTokenEndpoint($provider->tokenUrl, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $creds->clientId,
            'client_secret' => $creds->clientSecret,
        ]);
        $this->writeTokens($channel, $payload);
        $this->em->flush();
        return (string) ($payload['access_token'] ?? '');
    }

    public function encodeState(string $channelId): string
    {
        $nonce = bin2hex(random_bytes(8));
        $payload = $channelId . '.' . $nonce;
        $sig = hash_hmac('sha256', $payload, $this->stateSecret);
        return $payload . '.' . $sig;
    }

    public function decodeState(string $state): string
    {
        $parts = explode('.', $state);
        if (\count($parts) !== 3) {
            throw new OAuth2TokenException('Malformed OAuth state token.');
        }
        [$channelId, $nonce, $sig] = $parts;
        $expected = hash_hmac('sha256', $channelId . '.' . $nonce, $this->stateSecret);
        if (!hash_equals($expected, $sig)) {
            throw new OAuth2TokenException('OAuth state signature mismatch — possible CSRF.');
        }
        return $channelId;
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    private function postTokenEndpoint(string $url, array $form): array
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'body' => $form,
                'headers' => ['Accept' => 'application/json'],
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw new OAuth2TokenException('Token endpoint unreachable: ' . $e->getMessage(), 0, $e);
        }
        if ($status >= 400) {
            $error = (string) ($body['error_description'] ?? $body['error'] ?? 'unknown');
            throw new OAuth2TokenException("OAuth token endpoint returned $status: $error");
        }
        return $body;
    }

    /**
     * @param array<string, mixed> $tokenResponse
     */
    private function writeTokens(Channel $channel, array $tokenResponse): void
    {
        $auth = $channel->getAuthConfig();
        $auth['accessToken'] = (string) ($tokenResponse['access_token'] ?? '');
        // Refresh-token rotation: providers MAY return a new refresh
        // token on each refresh. Only overwrite when the response
        // included one — otherwise keep the existing.
        if (!empty($tokenResponse['refresh_token'])) {
            $auth['refreshToken'] = (string) $tokenResponse['refresh_token'];
        }
        $auth['scope'] = (string) ($tokenResponse['scope'] ?? ($auth['scope'] ?? ''));
        $auth['tokenType'] = (string) ($tokenResponse['token_type'] ?? 'Bearer');
        $expiresIn = (int) ($tokenResponse['expires_in'] ?? 3600);
        $auth['expiresAt'] = (new \DateTimeImmutable())
            ->modify("+{$expiresIn} seconds")
            ->format(\DateTimeInterface::ATOM);
        $channel->setAuthConfig($auth);
    }
}
