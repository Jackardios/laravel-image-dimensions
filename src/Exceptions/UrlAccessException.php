<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Exceptions;

use Jackardios\ImageDimensions\Support\UrlRedactor;
use Throwable;

class UrlAccessException extends ImageDimensionsException
{
    public static function couldNotOpen(string $url, ?Throwable $previous = null, ?int $statusCode = null): self
    {
        // The previous exception, kept for debugging, may quote the full URL.
        $message = 'Could not open URL: '.UrlRedactor::redact($url);
        if ($statusCode !== null) {
            $message .= " (HTTP {$statusCode})";
        }

        return new self($message, previous: $previous);
    }
}
