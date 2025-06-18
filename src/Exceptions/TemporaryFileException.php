<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Exceptions;

class TemporaryFileException extends ImageDimensionsException
{
    public static function couldNotCreate(): self
    {
        return new self("Could not create temporary file.");
    }

    public static function couldNotWrite(): self
    {
        return new self("Could not open temporary file for writing.");
    }
}


