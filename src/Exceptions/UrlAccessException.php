<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Exceptions;

use Throwable;

class UrlAccessException extends ImageDimensionsException
{
    public static function couldNotOpen(string $url, ?Throwable $previous = null): self
    {
        return new self("Could not open URL: {$url}", 0, $previous);
    }

    public static function couldNotDownload(string $url, ?Throwable $previous = null): self
    {
        return new self("Could not download full content from URL: {$url}", 0, $previous);
    }
}