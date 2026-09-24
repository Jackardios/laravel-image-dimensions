<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Support;

use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;

/**
 * Brings a URL into the one form that is checked, cached and fetched.
 *
 * URLs are accepted as a browser takes them when typed: with an
 * internationalized host name, underscores in the host, spaces or other
 * non-ASCII characters in the path. The host becomes ASCII (punycode,
 * lower case), the rest is percent-encoded, a default port and the fragment
 * are dropped. The SSRF guard, the cache key and the HTTP client then all see
 * the same URL.
 *
 * @internal
 */
final class UrlNormalizer
{
    /**
     * UTS #46 without transitional processing, as browsers apply it: "faß.de"
     * stays "faß.de" rather than becoming "fass.de".
     */
    private const IDNA_OPTIONS = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;

    /**
     * @return string|null The normalized URL, or null if it is not a valid
     *                     absolute URL with a host.
     */
    public static function normalize(string $url): ?string
    {
        // As browsers do, ignore control characters and spaces around the
        // URL; any left inside make it invalid.
        $url = trim($url, "\x00..\x20");
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        try {
            $uri = new Uri($url);
        } catch (InvalidArgumentException) {
            // MalformedUriException in guzzlehttp/psr7 2.x.
            return null;
        }

        $host = self::normalizeHost($uri->getHost());
        if ($host === null || $uri->getScheme() === '') {
            return null;
        }

        try {
            return (string) $uri->withHost($host)->withFragment('');
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * A host name in ASCII and lower case, an IPv4 address, or an IPv6
     * address in brackets; null if it is none of these.
     */
    public static function normalizeHost(string $host): ?string
    {
        if (str_starts_with($host, '[')) {
            $address = substr($host, 1, -1);

            return str_ends_with($host, ']') && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? strtolower($host)
                : null;
        }

        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $host = idn_to_ascii($host, self::IDNA_OPTIONS, INTL_IDNA_VARIANT_UTS46);
            if ($host === false) {
                return null;
            }
        }

        $host = strtolower($host);

        // Labels of letters, digits, hyphens (not at either end) and
        // underscores, which are common in bucket and service names.
        $label = '[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?';

        return strlen(rtrim($host, '.')) <= 253 && preg_match("/^(?:{$label}\\.)*{$label}\\.?$/", $host) === 1
            ? $host
            : null;
    }
}
