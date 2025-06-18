<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Exceptions;

class InvalidImageException extends ImageDimensionsException
{
    public static function forPath(string $path): self
    {
        return new self("Could not get image dimensions for file: {$path}");
    }
}


