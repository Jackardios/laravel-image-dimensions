<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Exceptions;

class InvalidImageException extends ImageDimensionsException
{
    public static function forPath(string $path, ?string $reason = null): self
    {
        $message = "Could not get image dimensions for file: {$path}";
        if ($reason) {
            $message .= " Reason: {$reason}";
        }
        return new self($message);
    }
}