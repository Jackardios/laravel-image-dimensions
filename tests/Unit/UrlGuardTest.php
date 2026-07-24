<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Unit;

use Jackardios\ImageDimensions\Exceptions\UrlNotAllowedException;
use Jackardios\ImageDimensions\Support\UrlGuard;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class UrlGuardTest extends TestCase
{
    #[Test]
    #[DataProvider('blockedIpProvider')]
    public function it_blocks_private_and_reserved_addresses(string $ip): void
    {
        $guard = new UrlGuard(allowPrivateHosts: false);
        $this->assertTrue($guard->isBlockedIp($ip), "{$ip} should be blocked");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedIpProvider(): array
    {
        return [
            'ipv4 loopback' => ['127.0.0.1'],
            'ipv4 private 10' => ['10.0.0.5'],
            'ipv4 private 172' => ['172.16.0.1'],
            'ipv4 private 192' => ['192.168.1.1'],
            'ipv4 link-local/meta' => ['169.254.169.254'],
            'ipv4 unspecified' => ['0.0.0.0'],
            'ipv4 cgnat' => ['100.64.0.1'],
            'ipv4 test-net' => ['192.0.2.1'],
            'ipv4 benchmark' => ['198.18.0.1'],
            'ipv6 loopback' => ['::1'],
            'ipv6 ula' => ['fc00::1'],
            'ipv6 link-local' => ['fe80::1'],
            'ipv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'ipv4-mapped private' => ['::ffff:10.0.0.1'],
            // Transition mechanisms tunnelling a private IPv4 inside IPv6.
            '6to4 loopback' => ['2002:7f00:1::'],
            '6to4 private 10' => ['2002:a00:1::'],
            '6to4 private 192.168' => ['2002:c0a8:1::'],
            '6to4 metadata' => ['2002:a9fe:a9fe::'],
            'nat64 loopback' => ['64:ff9b::7f00:1'],
            'nat64 private 10' => ['64:ff9b::a00:1'],
            'ipv6 documentation' => ['2001:db8::1'],
            'ipv6 discard' => ['100::1'],
            'ipv6 teredo' => ['2001:0::1'],
        ];
    }

    #[Test]
    #[DataProvider('publicIpProvider')]
    public function it_allows_public_addresses(string $ip): void
    {
        $guard = new UrlGuard(allowPrivateHosts: false);
        $this->assertFalse($guard->isBlockedIp($ip), "{$ip} should be allowed");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function publicIpProvider(): array
    {
        return [
            'ipv4 public a' => ['8.8.8.8'],
            'ipv4 public b' => ['1.1.1.1'],
            'ipv6 public' => ['2606:4700:4700::1111'],
            'ipv6 public google' => ['2a00:1450:4001:800::200e'],
            // The transition-prefix checks must not over-block: these tunnel a
            // PUBLIC IPv4 and stay reachable.
            '6to4 public v4' => ['2002:d83a:d54b::'],
            'nat64 public v4' => ['64:ff9b::d83a:d54b'],
        ];
    }

    #[Test]
    public function it_rejects_a_url_that_resolves_to_a_private_address(): void
    {
        $guard = new UrlGuard(allowPrivateHosts: false);

        $this->expectException(UrlNotAllowedException::class);
        $guard->assertAllowed('http://127.0.0.1/admin');
    }

    #[Test]
    public function it_rejects_the_aws_metadata_endpoint(): void
    {
        $guard = new UrlGuard(allowPrivateHosts: false);

        $this->expectException(UrlNotAllowedException::class);
        $guard->assertAllowed('http://169.254.169.254/latest/meta-data/');
    }

    #[Test]
    public function it_allows_a_url_that_resolves_to_a_public_address(): void
    {
        $guard = new UrlGuard(allowPrivateHosts: false);

        $guard->assertAllowed('https://8.8.8.8/image.png');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_rejects_non_http_schemes(): void
    {
        $guard = new UrlGuard(allowPrivateHosts: false);

        $this->expectException(UrlNotAllowedException::class);
        $guard->assertAllowed('ftp://8.8.8.8/image.png');
    }

    #[Test]
    public function allow_private_hosts_bypasses_ip_checks(): void
    {
        $guard = new UrlGuard(allowPrivateHosts: true);

        $guard->assertAllowed('http://127.0.0.1/admin');
        $guard->assertAllowed('http://169.254.169.254/');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function an_allowlist_rejects_hosts_that_are_not_listed(): void
    {
        $guard = new UrlGuard(allowPrivateHosts: true, allowedHosts: ['cdn.example.com']);

        $this->expectException(UrlNotAllowedException::class);
        $guard->assertAllowed('https://evil.example.org/image.png');
    }

    #[Test]
    public function an_allowlist_admits_listed_hosts(): void
    {
        $guard = new UrlGuard(allowPrivateHosts: true, allowedHosts: ['cdn.example.com']);

        $guard->assertAllowed('https://cdn.example.com/image.png');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_redirect_guard_re_validates_each_hop(): void
    {
        $guard = new UrlGuard(allowPrivateHosts: false);
        $onRedirect = $guard->redirectGuard();

        $this->expectException(UrlNotAllowedException::class);
        $onRedirect(null, null, 'http://127.0.0.1/internal');
    }
}
