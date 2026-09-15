<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Exceptions;

/**
 * Thrown when a URL is rejected by the SSRF guard (private/reserved network,
 * unresolvable host, disallowed scheme, or not on the configured allowlist).
 *
 * Extends UrlAccessException so existing catch (UrlAccessException) blocks keep
 * working after the upgrade.
 */
class UrlNotAllowedException extends UrlAccessException
{
    public static function invalidScheme(string $scheme): self
    {
        return new self("URL scheme '{$scheme}' is not allowed; only http and https are supported.");
    }

    public static function invalidHost(string $url): self
    {
        return new self("Could not determine a host for URL: {$url}");
    }

    public static function notInAllowlist(string $host): self
    {
        return new self("Host '{$host}' is not in the configured allowlist.");
    }

    public static function unresolvableHost(string $host): self
    {
        return new self("Host '{$host}' could not be resolved to an IP address.");
    }

    public static function blockedAddress(string $host, string $ip): self
    {
        return new self("Host '{$host}' resolves to a blocked address ({$ip}).");
    }
}
