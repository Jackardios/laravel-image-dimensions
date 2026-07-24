<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Exceptions;

/**
 * Thrown when a source exceeds a configured size limit.
 *
 * Extends InvalidImageException so existing catch (InvalidImageException) blocks
 * keep working after the upgrade.
 */
class FileTooLargeException extends InvalidImageException
{
    public static function forSvg(int $maxBytes): self
    {
        return new self("SVG file is too large (max {$maxBytes} bytes).");
    }

    public static function forDownload(int $maxBytes): self
    {
        return new self("Remote file is too large to download (max {$maxBytes} bytes).");
    }
}
