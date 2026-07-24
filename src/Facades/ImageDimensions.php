<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Jackardios\ImageDimensions\Dimensions fromLocal(string $path)
 * @method static \Jackardios\ImageDimensions\Dimensions fromUrl(string $url)
 * @method static \Jackardios\ImageDimensions\Dimensions fromStorage(string $diskName, string $path)
 *
 * @see \Jackardios\ImageDimensions\ImageDimensionsService
 */
class ImageDimensions extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'image-dimensions';
    }
}
