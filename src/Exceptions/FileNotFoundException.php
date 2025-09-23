<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Exceptions;

class FileNotFoundException extends ImageDimensionsException
{
    public static function forLocal(string $path): self
    {
        return new self("Local file not found: {$path}");
    }

    public static function forStorage(string $diskName, string $path): self
    {
        return new self("File not found on disk '{$diskName}': {$path}");
    }
}


