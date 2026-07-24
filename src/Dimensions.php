<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Stringable;

/**
 * Immutable value object describing the pixel dimensions of an image.
 *
 * Implements ArrayAccess and JsonSerializable so that code written against the
 * v1 array return value (`$result['width']`, `json_encode($result)`) keeps
 * working after the upgrade.
 *
 * @implements ArrayAccess<string, int>
 * @implements Arrayable<string, int>
 */
final readonly class Dimensions implements Arrayable, ArrayAccess, JsonSerializable, Stringable
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException(
                "Image dimensions must be positive integers, got {$width}x{$height}."
            );
        }
    }

    /**
     * Build a Dimensions instance from an associative array.
     *
     * @param  array{width: int|numeric-string, height: int|numeric-string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self((int) $data['width'], (int) $data['height']);
    }

    /**
     * The aspect ratio (width / height).
     */
    public function ratio(): float
    {
        return $this->width / $this->height;
    }

    public function isLandscape(): bool
    {
        return $this->width > $this->height;
    }

    public function isPortrait(): bool
    {
        return $this->height > $this->width;
    }

    public function isSquare(): bool
    {
        return $this->width === $this->height;
    }

    /**
     * @return array{width: int, height: int}
     */
    public function toArray(): array
    {
        return ['width' => $this->width, 'height' => $this->height];
    }

    /**
     * @return array{width: int, height: int}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return "{$this->width}x{$this->height}";
    }

    public function offsetExists(mixed $offset): bool
    {
        return $offset === 'width' || $offset === 'height';
    }

    public function offsetGet(mixed $offset): int
    {
        return match ($offset) {
            'width' => $this->width,
            'height' => $this->height,
            default => throw new InvalidArgumentException('Unknown dimension offset: '.var_export($offset, true)),
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Dimensions are immutable and cannot be modified.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Dimensions are immutable and cannot be modified.');
    }
}
