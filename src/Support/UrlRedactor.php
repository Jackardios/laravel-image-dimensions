<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Support;

/**
 * A URL as it may appear in an exception message: without the secrets
 * URLs often carry, which would otherwise end up in logs and error trackers.
 *
 * User info is replaced as a whole, query values are replaced but their
 * names kept (a signed URL's `X-Amz-Signature=***` still tells what it was),
 * and the fragment is dropped. Works on invalid URLs too.
 *
 * @internal
 */
final class UrlRedactor
{
    private const MASK = '***';

    public static function redact(string $url): string
    {
        $url = explode('#', $url, 2)[0];
        $parts = explode('?', $url, 2);
        $url = $parts[0];
        $query = $parts[1] ?? null;

        // User info: everything before the last "@" of the authority.
        $url = (string) preg_replace('~^([^:/?]*:)?//[^/]*@~', '$1//'.self::MASK.'@', $url);

        if ($query === null) {
            return $url;
        }

        $parameters = array_map(
            static fn (string $parameter): string => str_contains($parameter, '=')
                ? explode('=', $parameter, 2)[0].'='.self::MASK
                : ($parameter === '' ? '' : self::MASK),
            explode('&', $query),
        );

        return $url.'?'.implode('&', $parameters);
    }
}
