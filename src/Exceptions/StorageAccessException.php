<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Exceptions;

use Throwable;

class StorageAccessException extends ImageDimensionsException
{
    public static function couldNotReadStream(string $path, ?Throwable $previous = null): self
    {
        return new self("Could not read stream from storage file: {$path}", 0, $previous);
    }
}
