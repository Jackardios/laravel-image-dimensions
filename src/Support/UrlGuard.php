<?php declare(strict_types=1);

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

    private bool $allowPrivateHosts;

    /** @var list<string> Lower-cased allowed hostnames; empty means "any". */
    private array $allowedHosts;

    private int $maxRedirects;

    /**
     * @param array<int, string> $allowedHosts
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
        if (!is_string($host) || $host === '') {
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
            && !in_array($host, $this->allowedHosts, true)
            && !in_array($bareHost, $this->allowedHosts, true)
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
     * @throws UrlNotAllowedException
     */
    private function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
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
            throw UrlNotAllowedException::unresolvableHost($host);
        }

        return array_values(array_unique($ips));
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

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
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
