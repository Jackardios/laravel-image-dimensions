<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Exceptions;

class StorageAccessException extends ImageDimensionsException
{
    public static function couldNotReadStream(string $path): self
    {
        return new self("Could not read stream from storage file: {$path}");
    }
}
