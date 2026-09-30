<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Support;

use RuntimeException;

/**
 * Thrown from a transfer callback to end a download early, once the part of
 * the body that has arrived gives the dimensions. Never escapes the service.
 *
 * @internal
 */
final class TransferStopped extends RuntimeException
{
    /**
     * @param  array{width: int, height: int}  $dimensions
     */
    public function __construct(public readonly array $dimensions)
    {
        parent::__construct('The transfer was stopped once the dimensions were known.');
    }
}
