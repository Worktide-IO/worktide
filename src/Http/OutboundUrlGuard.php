<?php

declare(strict_types=1);

namespace App\Http;

/**
 * SSRF guard for user-configured outbound URLs (webhooks, and any future
 * caller that fetches an operator-supplied URL).
 *
 * It validates the scheme, resolves the host, and rejects any host that
 * resolves to a private / loopback / link-local / reserved address — including
 * the cloud-metadata endpoint 169.254.169.254 and IPv4-mapped IPv6 forms.
 *
 * It returns the validated host + the resolved public IP so the caller can PIN
 * the connection to that IP (Symfony HttpClient `resolve` option). Pinning
 * closes the DNS-rebinding TOCTOU window: without it, the host could resolve to
 * a public IP here and to an internal one microseconds later when the request
 * actually connects. Callers should also disable redirects (`max_redirects: 0`),
 * otherwise a 3xx to an internal URL would bypass this check entirely.
 */
final class OutboundUrlGuard
{
    /**
     * @return array{host: string, ip: string} validated host + public IP to pin the connection to
     *
     * @throws UnsafeUrlException when the URL is not a safe public http(s) target
     */
    public function assertPublicHttpUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrlException('URL must be an absolute http(s) URL.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new UnsafeUrlException(\sprintf('URL scheme "%s" is not allowed (http/https only).', $scheme));
        }

        // parse_url strips the brackets from IPv6 literals, but trim defensively.
        $host = trim((string) $parts['host'], '[]');
        if ($host === '') {
            throw new UnsafeUrlException('URL has no host.');
        }

        $ips = $this->resolve($host);
        if ($ips === []) {
            throw new UnsafeUrlException(\sprintf('Host "%s" does not resolve.', $host));
        }

        // Reject if ANY resolved address is non-public — a host that returns one
        // public and one private record must not be reachable at all.
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new UnsafeUrlException(\sprintf(
                    'Host "%s" resolves to a non-public address (%s); refusing to connect.',
                    $host,
                    $ip,
                ));
            }
        }

        return ['host' => $host, 'ip' => $ips[0]];
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            \FILTER_VALIDATE_IP,
            \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * @return list<string> every A + AAAA address for the host (or the literal
     *                      itself when the host is already an IP)
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = gethostbynamel($host) ?: [];
        $aaaa = @dns_get_record($host, \DNS_AAAA) ?: [];
        foreach ($aaaa as $record) {
            if (isset($record['ipv6']) && \is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
