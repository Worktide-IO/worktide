<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\OutboundUrlGuard;
use App\Http\UnsafeUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SSRF guard for operator-supplied outbound URLs. Uses IP-literal URLs so the
 * assertions are deterministic (no DNS lookups): private / loopback /
 * link-local / metadata / reserved targets are refused, public ones pass and
 * return the IP to pin.
 */
final class OutboundUrlGuardTest extends TestCase
{
    private OutboundUrlGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new OutboundUrlGuard();
    }

    #[DataProvider('unsafeUrls')]
    public function testRejectsUnsafeUrls(string $url): void
    {
        $this->expectException(UnsafeUrlException::class);
        $this->guard->assertPublicHttpUrl($url);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeUrls(): iterable
    {
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'loopback v4' => ['http://127.0.0.1:8080/internal'];
        yield 'private 10/8' => ['https://10.0.0.5/admin'];
        yield 'private 172.16/12' => ['http://172.16.9.9/'];
        yield 'private 192.168/16' => ['http://192.168.1.1/'];
        yield 'unspecified' => ['http://0.0.0.0/'];
        yield 'loopback v6' => ['http://[::1]/'];
        yield 'link-local v6' => ['http://[fe80::1]/'];
        yield 'ula v6' => ['http://[fd00::1]/'];
        yield 'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'];
        yield 'bad scheme ftp' => ['ftp://example.com/x'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'gopher scheme' => ['gopher://127.0.0.1/'];
        yield 'no scheme' => ['example.com/path'];
        yield 'garbage' => ['not a url'];
        yield 'empty' => [''];
    }

    public function testAllowsPublicIpLiteral(): void
    {
        $target = $this->guard->assertPublicHttpUrl('https://8.8.8.8:1234/hook?x=1');
        self::assertSame('8.8.8.8', $target['host']);
        self::assertSame('8.8.8.8', $target['ip']);
    }

    public function testAllowsPlainHttpPublicIp(): void
    {
        $target = $this->guard->assertPublicHttpUrl('http://1.1.1.1/');
        self::assertSame('1.1.1.1', $target['ip']);
    }
}
