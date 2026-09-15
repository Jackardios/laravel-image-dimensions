<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Exceptions;

use Throwable;

class UrlAccessException extends ImageDimensionsException
{
    public static function couldNotOpen(string $url, ?Throwable $previous = null, ?int $statusCode = null): self
    {
        $message = "Could not open URL: {$url}";
        if ($statusCode !== null) {
            $message .= " (HTTP {$statusCode})";
        }

        return new self($message, 0, $previous);
    }
}
