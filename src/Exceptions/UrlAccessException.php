<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Exceptions;

class UrlAccessException extends ImageDimensionsException
{
    public static function couldNotOpen(string $url): self
    {
        return new self("Could not open URL: {$url}");
    }

    public static function couldNotDownload(string $url): self
    {
        return new self("Could not download full content from URL: {$url}");
    }
}


