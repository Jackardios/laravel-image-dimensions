<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Jackardios\ImageDimensions\Dimensions fromLocal(string $path)
 * @method static \Jackardios\ImageDimensions\Dimensions fromUrl(string $url)
 * @method static \Jackardios\ImageDimensions\Dimensions fromStorage(string $diskName, string $path)
 * @method static \Jackardios\ImageDimensions\Dimensions fromContents(string $contents)
 * @method static \Jackardios\ImageDimensions\Dimensions fromStream(resource $stream)
 * @method static \Jackardios\ImageDimensions\Dimensions fromUploadedFile(\SplFileInfo $file)
 * @method static \Jackardios\ImageDimensions\Dimensions|null tryFromLocal(string $path)
 * @method static \Jackardios\ImageDimensions\Dimensions|null tryFromUrl(string $url)
 * @method static \Jackardios\ImageDimensions\Dimensions|null tryFromStorage(string $diskName, string $path)
 * @method static \Jackardios\ImageDimensions\Dimensions|null tryFromContents(string $contents)
 * @method static \Jackardios\ImageDimensions\Dimensions|null tryFromStream(resource $stream)
 * @method static \Jackardios\ImageDimensions\Dimensions|null tryFromUploadedFile(\SplFileInfo $file)
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
