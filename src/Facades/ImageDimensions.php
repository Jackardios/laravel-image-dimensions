<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array<string, int> fromLocal(string $path)
 * @method static array<string, int> fromUrl(string $url)
 * @method static array<string, int> fromStorage(string $diskName, string $path)
 *
 * @see \Jackardios\ImageDimensions\ImageDimensionsService
 */
class ImageDimensions extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return "image-dimensions";
    }
}