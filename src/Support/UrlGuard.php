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
 * IPv4-mapped IPv6). This blocks access to cloud metadata endpoints
 * (169.254.169.254), localhost, and internal services.
 *
 * Residual risk: DNS rebinding. The host is resolved here and again by the HTTP
 * client, so a hostile resolver could return a public address to this check and
 * a private one to the actual request. Use `allowed_hosts` for a strict
 * allowlist in high-sensitivity environments.
 */
final class UrlGuard
{
    /**
     * Reserved IPv4 ranges (CIDR) not covered by PHP's NO_PRIV_RANGE /
     * NO_RES_RANGE filter flags but still unsafe to reach.
     *
     * @var list<array{0: string, 1: int}>
     */
    private const EXTRA_BLOCKED_V4 = [
        ['100.64.0.0', 10], // RFC 6598 carrier-grade NAT
        ['192.0.0.0', 24],  // RFC 6890 IETF protocol assignments
        ['192.0.2.0', 24],  // RFC 5737 TEST-NET-1
        ['198.18.0.0', 15], // RFC 2544 benchmarking
        ['198.51.100.0', 24], // RFC 5737 TEST-NET-2
        ['203.0.113.0', 24],  // RFC 5737 TEST-NET-3
    ];

    /**
     * Reserved IPv6 prefixes not covered by the filter flags.
     *
     * @var list<array{0: string, 1: int}>
     */
    private const EXTRA_BLOCKED_V6 = [
        ['2001:db8::', 32], // RFC 3849 documentation
        ['100::', 64],      // RFC 6666 discard-only
        ['2001::', 32],     // RFC 4380 Teredo
    ];

    private bool $allowPrivateHosts;

    /** @var list<string> Lower-cased allowed hostnames; empty means "any". */
    private array $allowedHosts;

    private int $maxRedirects;

    /**
     * Per-instance memo of host => resolved IPs, so repeated lookups of the same
     * host (cache hits, redirect chains, many images on one page) do not each
     * pay two blocking DNS queries.
     *
     * @var array<string, list<string>>
     */
    private array $resolvedHosts = [];

    /**
     * @param  array<int, string>  $allowedHosts
     */
    public function __construct(bool $allowPrivateHosts = false, array $allowedHosts = [], int $maxRedirects = 5)
    {
        $this->allowPrivateHosts = $allowPrivateHosts;
        $this->allowedHosts = array_values(array_filter(array_map(
            static fn ($host) => strtolower(trim((string) $host)),
            $allowedHosts
        ), static fn (string $host) => $host !== ''));
        $this->maxRedirects = max(0, $maxRedirects);
    }

    public function maxRedirects(): int
    {
        return $this->maxRedirects;
    }

    /**
     * Assert that a URL may be fetched.
     *
     * @throws UrlNotAllowedException
     */
    public function assertAllowed(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw UrlNotAllowedException::invalidScheme($scheme === '' ? '(none)' : $scheme);
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw UrlNotAllowedException::invalidHost($url);
        }

        $this->assertHostAllowed($host);
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
     * @throws UrlNotAllowedException
     */
    private function assertHostAllowed(string $host): void
    {
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

        foreach ($this->resolveIps($bareHost) as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw UrlNotAllowedException::blockedAddress($host, $ip);
            }
        }
    }

    /**
     * Resolve a host to every IP a later request might connect to.
     *
     * @return list<string>
     *
     * @throws UrlNotAllowedException
     */
    private function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (isset($this->resolvedHosts[$host])) {
            return $this->resolvedHosts[$host];
        }

        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            // Unresolvable: cannot prove the target is safe, so refuse it.
            // Not memoized — a transient DNS failure should not stick.
            throw UrlNotAllowedException::unresolvableHost($host);
        }

        return $this->resolvedHosts[$host] = array_values(array_unique($ips));
    }

    /**
     * Whether an IP address is in a private or reserved range.
     */
    public function isBlockedIp(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) — evaluate the embedded IPv4.
        if (stripos($ip, '::ffff:') === 0) {
            $mapped = substr($ip, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $this->isBlockedIp($mapped);
            }
        }

        // Transition mechanisms that tunnel an IPv4 destination inside an IPv6
        // address: 6to4 (2002:V4ADDR::/48) and NAT64 (64:ff9b::/96). On a host
        // with such a route these reach the embedded IPv4, so judge that.
        $embedded = $this->embeddedIpv4($ip);
        if ($embedded !== null && $this->isBlockedIp($embedded)) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        // Reserved IPv6 ranges the filter flags do not cover.
        foreach (self::EXTRA_BLOCKED_V6 as $prefix) {
            if ($this->ipv6InPrefix($ip, $prefix[0], $prefix[1])) {
                return true;
            }
        }

        // Extra IPv4 ranges the filter flags do not cover.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::EXTRA_BLOCKED_V4 as [$subnet, $bits]) {
                if ($this->ipv4InCidr($ip, $subnet, $bits)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract the IPv4 address tunnelled inside a 6to4 or NAT64 IPv6 address,
     * or null when the address carries none.
     */
    private function embeddedIpv4(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        // 6to4: 2002:AABB:CCDD::/48 embeds A.B.C.D at bytes 2-5.
        if (str_starts_with($packed, "\x20\x02")) {
            return inet_ntop(substr($packed, 2, 4)) ?: null;
        }

        // NAT64 well-known prefix 64:ff9b::/96 embeds the IPv4 in the last 4 bytes.
        if (str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\x00", 8))) {
            return inet_ntop(substr($packed, 12, 4)) ?: null;
        }

        return null;
    }

    /**
     * Whether an IPv6 address falls inside a prefix, compared bitwise on the
     * packed form.
     */
    private function ipv6InPrefix(string $ip, string $prefix, int $bits): bool
    {
        $packedIp = @inet_pton($ip);
        $packedPrefix = @inet_pton($prefix);

        if ($packedIp === false || $packedPrefix === false
            || strlen($packedIp) !== 16 || strlen($packedPrefix) !== 16
        ) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes > 0 && strncmp($packedIp, $packedPrefix, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedPrefix[$wholeBytes]) & $mask);
    }

    private function ipv4InCidr(string $ip, string $subnet, int $bits): bool
    {
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
