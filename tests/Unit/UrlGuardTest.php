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
            'ipv4 test-net-2' => ['198.51.100.1'],
            'ipv4 test-net-3' => ['203.0.113.1'],
            'ipv4 ietf protocol assignments' => ['192.0.0.1'],
            'ipv4 reserved' => ['240.0.0.1'],
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
            // Allowed by the PHP 8.2 filter tables, or by every version's.
            'ipv4-compatible loopback' => ['::127.0.0.1'],
            'ipv4-compatible loopback, hex' => ['::7f00:1'],
            'ipv4-compatible metadata, hex' => ['::a9fe:a9fe'],
            'ipv4-mapped loopback, hex' => ['::ffff:7f00:1'],
            'ipv4-mapped loopback, long form' => ['0:0:0:0:0:FFFF:7F00:0001'],
            'ipv4-translated (SIIT) metadata' => ['::ffff:0:a9fe:a9fe'],
            'ipv6 unspecified' => ['::'],
            // The rest of ::/8 is reserved too, whatever IPv4 address its
            // last bits spell; only the mapped and NAT64 prefixes carry one.
            'reserved ::/8' => ['::1:a00:1'],
            'reserved ::/8 spelling a public ipv4' => ['::1:808:808'],
            'nat64 prefix, but not /96' => ['64:ff9b::1:808:808'],
            'nat64 /32 outside the assigned blocks' => ['64:ff9b:0:1::808:808'],
            'local-use nat64' => ['64:ff9b:1::a00:1'],
            'orchid' => ['2001:10::1'],
            'orchid v2' => ['2001:20::1'],
            'benchmarking v6' => ['2001:2::1'],
            'drip' => ['2001:30::1'],
            'documentation 3fff' => ['3fff::1'],
            'srv6 sid' => ['5f00::1'],
            'site-local' => ['fec0::1'],
            'ipv6 multicast' => ['ff02::1'],
            'ipv4 multicast' => ['224.0.0.1'],
            'ipv4 broadcast' => ['255.255.255.255'],
            '6to4 relay anycast' => ['192.88.99.1'],
            'not an ip' => ['localhost'],
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
            '6to4 public v4, another' => ['2002:808:808::'],
            'nat64 public v4' => ['64:ff9b::d83a:d54b'],
            'ipv4-mapped public' => ['::ffff:8.8.8.8'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function addressUrlProvider(): array
    {
        return [
            'ipv4 loopback' => ['http://127.0.0.1/admin', false],
            'metadata endpoint' => ['http://169.254.169.254/latest/meta-data/', false],
            'ipv4 public' => ['https://8.8.8.8/image.png', true],
            'ipv6 loopback' => ['http://[::1]/admin', false],
            'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/admin', false],
            'ipv6 public' => ['http://[2606:4700:4700::1111]/image.png', true],
        ];
    }

    /**
     * An address in the URL is judged as it is, without DNS.
     */
    #[Test]
    #[DataProvider('addressUrlProvider')]
    public function it_judges_an_address_in_the_url(string $url, bool $allowed): void
    {
        $guard = new UrlGuard(allowPrivateHosts: false, resolver: function () {
            $this->fail('No DNS lookup expected for an address.');
        });

        if (! $allowed) {
            $this->expectException(UrlNotAllowedException::class);
            $this->expectExceptionMessage('resolves to a private or reserved address');
        }

        $guard->assertAllowed($url);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function an_allowlist_entry_may_be_an_ipv6_address(): void
    {
        foreach (['2606:4700:4700::1111', '[2606:4700:4700::1111]'] as $entry) {
            $guard = new UrlGuard(allowPrivateHosts: false, allowedHosts: [$entry]);
            $guard->assertAllowed('http://[2606:4700:4700::1111]/image.png');

            try {
                $guard->assertAllowed('http://[2606:4700:4700::1112]/image.png');
                $this->fail("{$entry} must admit only itself.");
            } catch (UrlNotAllowedException $e) {
                $this->assertStringContainsString('is not in the configured allowlist', $e->getMessage());
            }
        }
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
    public function a_url_without_a_host_is_reported_without_its_secrets(): void
    {
        try {
            (new UrlGuard)->assertAllowed('https:/a.png?token=hunter2');
            $this->fail('Expected a UrlNotAllowedException.');
        } catch (UrlNotAllowedException $e) {
            $this->assertSame('Could not determine a host for URL: https:/a.png?token=***', $e->getMessage());
        }
    }

    #[Test]
    public function an_allowlist_entry_matches_the_punycode_of_an_internationalized_host(): void
    {
        $guard = new UrlGuard(allowPrivateHosts: true, allowedHosts: ['Пример.РФ']);

        $guard->assertAllowed('https://xn--e1afmkfd.xn--p1ai/a.png');

        $this->expectException(UrlNotAllowedException::class);
        $guard->assertAllowed('https://example.com/a.png');
    }

    #[Test]
    public function it_accepts_the_scheme_in_any_case(): void
    {
        $guard = new UrlGuard(allowPrivateHosts: false);

        $guard->assertAllowed('HTTPS://8.8.8.8/image.png');
        $guard->assertAllowed('Http://8.8.8.8/image.png');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_rejects_a_host_that_resolves_to_a_private_address_without_naming_it(): void
    {
        $guard = new UrlGuard(allowPrivateHosts: false, resolver: fn (string $host) => ['8.8.8.8', '10.1.2.3']);

        try {
            $guard->assertAllowed('https://internal.example.com/image.png');
            $this->fail('Expected a UrlNotAllowedException.');
        } catch (UrlNotAllowedException $e) {
            $this->assertStringContainsString("'internal.example.com'", $e->getMessage());
            $this->assertStringNotContainsString('10.1.2.3', $e->getMessage());
        }
    }

    #[Test]
    public function it_allows_a_host_that_resolves_only_to_public_addresses(): void
    {
        $resolved = [];
        $guard = new UrlGuard(allowPrivateHosts: false, resolver: function (string $host) use (&$resolved) {
            $resolved[] = $host;

            return ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'];
        });

        $guard->assertAllowed('https://Example.COM/image.png');

        $this->assertSame(['example.com'], $resolved);
    }

    #[Test]
    public function it_rejects_a_host_that_does_not_resolve(): void
    {
        $guard = new UrlGuard(allowPrivateHosts: false, resolver: fn (string $host) => []);

        $this->expectException(UrlNotAllowedException::class);
        $this->expectExceptionMessage('could not be resolved');
        $guard->assertAllowed('https://nowhere.invalid/image.png');
    }

    /**
     * Regression: resolutions were memoized per guard, and the guard lives
     * as long as a queue or Octane worker, so a verdict outlived the DNS
     * record it was based on.
     */
    #[Test]
    public function it_resolves_the_host_again_on_every_check(): void
    {
        $answers = [['93.184.215.14'], ['127.0.0.1']];
        $guard = new UrlGuard(allowPrivateHosts: false, resolver: function (string $host) use (&$answers) {
            return array_shift($answers);
        });

        $guard->assertAllowed('https://example.com/a.png');

        $this->expectException(UrlNotAllowedException::class);
        $guard->assertAllowed('https://example.com/a.png');
    }

    #[Test]
    public function without_resolving_it_checks_everything_but_dns(): void
    {
        $guard = new UrlGuard(allowPrivateHosts: false, allowedHosts: ['example.com', '127.0.0.1'], resolver: function () {
            $this->fail('No DNS lookup expected.');
        });

        $guard->assertAllowed('https://example.com/a.png', resolve: false);

        foreach (['ftp://example.com/a.png', 'https://other.example/a.png', 'http://127.0.0.1/a.png'] as $url) {
            try {
                $guard->assertAllowed($url, resolve: false);
                $this->fail("Expected {$url} to be rejected.");
            } catch (UrlNotAllowedException) {
                $this->addToAssertionCount(1);
            }
        }
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
