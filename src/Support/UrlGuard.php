<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Support;

use Closure;
use Jackardios\ImageDimensions\Exceptions\UrlNotAllowedException;

/**
 * Guards outgoing image fetches against SSRF.
 *
 * By default a URL is rejected when its host resolves to a private, loopback,
 * link-local, or otherwise reserved IP address (IPv4 and IPv6, including
 * IPv4 addresses embedded in IPv6 ones). This blocks access to cloud metadata
 * endpoints (169.254.169.254), localhost, and internal services.
 *
 * The host is resolved again on every fetch, never remembered, so a verdict
 * cannot outlive the DNS record it was based on.
 *
 * Residual risk: DNS rebinding. The host is resolved here and again by the HTTP
 * client, so a hostile resolver could return a public address to this check and
 * a private one to the actual request. Use `allowed_hosts` for a strict
 * allowlist in high-sensitivity environments.
 */
final class UrlGuard
{
    /**
     * IPv4 ranges that are never fetched (IANA special-purpose registry and
     * multicast), listed explicitly so the verdict does not depend on the
     * PHP version's filter tables.
     *
     * @var list<array{0: string, 1: int}>
     */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],        // "this network"
        ['10.0.0.0', 8],       // private
        ['100.64.0.0', 10],    // carrier-grade NAT
        ['127.0.0.0', 8],      // loopback
        ['169.254.0.0', 16],   // link-local, cloud metadata
        ['172.16.0.0', 12],    // private
        ['192.0.0.0', 24],     // IETF protocol assignments
        ['192.0.2.0', 24],     // TEST-NET-1
        ['192.88.99.0', 24],   // 6to4 relay anycast (deprecated)
        ['192.168.0.0', 16],   // private
        ['198.18.0.0', 15],    // benchmarking
        ['198.51.100.0', 24],  // TEST-NET-2
        ['203.0.113.0', 24],   // TEST-NET-3
        ['224.0.0.0', 4],      // multicast
        ['240.0.0.0', 4],      // reserved, broadcast
    ];

    /**
     * IPv6 prefixes that are never fetched. Addresses that carry an IPv4
     * address (mapped, 6to4, NAT64) are judged by that address instead.
     *
     * @var list<array{0: string, 1: int}>
     */
    private const BLOCKED_V6 = [
        ['::', 96],             // unspecified, loopback, IPv4-compatible (deprecated)
        ['::ffff:0:0:0', 96],   // IPv4-translated (SIIT)
        ['64:ff9b:1::', 48],    // local-use IPv4/IPv6 translation
        ['100::', 64],          // discard-only
        ['2001::', 23],         // IETF protocol assignments: Teredo, ORCHID, ...
        ['2001:db8::', 32],     // documentation
        ['3fff::', 20],         // documentation
        ['5f00::', 16],         // segment routing SIDs
        ['fc00::', 7],          // unique local
        ['fe80::', 10],         // link-local
        ['fec0::', 10],         // site-local (deprecated)
        ['ff00::', 8],          // multicast
    ];

    private bool $allowPrivateHosts;

    /** @var list<string> Normalized allowed hostnames; empty means "any". */
    private array $allowedHosts;

    private int $maxRedirects;

    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /**
     * @param  array<int, string>  $allowedHosts
     * @param  (Closure(string): list<string>)|null  $resolver  Returns every IP address a host
     *                                                          resolves to. Defaults to the system resolver.
     */
    public function __construct(
        bool $allowPrivateHosts = false,
        array $allowedHosts = [],
        int $maxRedirects = 5,
        ?Closure $resolver = null,
    ) {
        $this->allowPrivateHosts = $allowPrivateHosts;
        // In the form URLs are normalized to, so "пример.рф" matches its
        // punycode.
        $this->allowedHosts = array_values(array_filter(array_map(
            static function ($host): string {
                $host = strtolower(trim((string) $host));

                return UrlNormalizer::normalizeHost($host) ?? $host;
            },
            $allowedHosts
        ), static fn (string $host) => $host !== ''));
        $this->maxRedirects = max(0, $maxRedirects);
        $this->resolver = $resolver ?? self::resolveWithSystemResolver(...);
    }

    public function maxRedirects(): int
    {
        return $this->maxRedirects;
    }

    /**
     * Assert that a URL may be fetched.
     *
     * With $resolve = false only what needs no DNS lookup is checked: the
     * scheme, the allowlist and a literal IP address. A URL must still pass
     * the full check before it is actually fetched.
     *
     * @throws UrlNotAllowedException
     */
    public function assertAllowed(string $url, bool $resolve = true): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw UrlNotAllowedException::invalidScheme($scheme === '' ? '(none)' : $scheme);
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw UrlNotAllowedException::invalidHost($url);
        }

        $host = strtolower($host);
        $bareHost = trim($host, '[]'); // strip IPv6 literal brackets

        if ($this->allowedHosts !== []
            && ! in_array($host, $this->allowedHosts, true)
            && ! in_array($bareHost, $this->allowedHosts, true)
        ) {
            throw UrlNotAllowedException::notInAllowlist($host);
        }

        if ($this->allowPrivateHosts) {
            return;
        }

        $isLiteral = filter_var($bareHost, FILTER_VALIDATE_IP) !== false;
        if (! $isLiteral && ! $resolve) {
            return;
        }

        $ips = $isLiteral ? [$bareHost] : ($this->resolver)($bareHost);
        if ($ips === []) {
            // Unresolvable: cannot prove the target is safe, so refuse it.
            throw UrlNotAllowedException::unresolvableHost($host);
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw UrlNotAllowedException::blockedAddress($host);
            }
        }
    }

    /**
     * A Guzzle `on_redirect` callback that re-validates every hop's target URI.
     */
    public function redirectGuard(): Closure
    {
        return function ($request, $response, $uri): void {
            $this->assertAllowed((string) $uri);
        };
    }

    /**
     * Whether an IP address is in a private, reserved or otherwise
     * non-public range. Anything that is not an IP address is blocked.
     */
    public function isBlockedIp(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 4) {
            return $this->isBlockedIpv4($packed);
        }

        $embedded = $this->embeddedIpv4($packed);
        if ($embedded !== null) {
            return $this->isBlockedIpv4($embedded);
        }

        foreach (self::BLOCKED_V6 as [$prefix, $bits]) {
            if ($this->inPrefix($packed, (string) inet_pton($prefix), $bits)) {
                return true;
            }
        }

        return ! $this->isGlobal((string) inet_ntop($packed));
    }

    private function isBlockedIpv4(string $packed): bool
    {
        foreach (self::BLOCKED_V4 as [$prefix, $bits]) {
            if ($this->inPrefix($packed, (string) inet_pton($prefix), $bits)) {
                return true;
            }
        }

        return ! $this->isGlobal((string) inet_ntop($packed));
    }

    /**
     * PHP's own verdict, as a second opinion: its tables differ between
     * versions, so it may only ever add to the explicit lists above.
     */
    private function isGlobal(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE
        ) !== false;
    }

    /**
     * The packed IPv4 address carried by an IPv4-mapped (::ffff:0:0/96), 6to4
     * (2002::/16) or NAT64 (64:ff9b::/96) address, or null for any other.
     * Such an address reaches the embedded IPv4 one, so it is judged by it.
     */
    private function embeddedIpv4(string $packed): ?string
    {
        if (str_starts_with($packed, str_repeat("\x00", 10)."\xff\xff")
            || str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\x00", 8))
        ) {
            return substr($packed, 12, 4);
        }

        if (str_starts_with($packed, "\x20\x02")) {
            return substr($packed, 2, 4);
        }

        return null;
    }

    /**
     * Whether a packed address falls inside a packed prefix of the same
     * family, compared bitwise.
     */
    private function inPrefix(string $packed, string $prefix, int $bits): bool
    {
        if (strlen($packed) !== strlen($prefix)) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        if (strncmp($packed, $prefix, $wholeBytes) !== 0) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packed[$wholeBytes]) & $mask) === (ord($prefix[$wholeBytes]) & $mask);
    }

    /**
     * Every IPv4 and IPv6 address a host resolves to.
     *
     * @return list<string>
     */
    private static function resolveWithSystemResolver(string $host): array
    {
        $ips = @gethostbynamel($host) ?: [];

        $records = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
