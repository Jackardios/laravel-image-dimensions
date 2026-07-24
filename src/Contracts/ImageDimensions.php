<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Contracts;

use Jackardios\ImageDimensions\Dimensions;
use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\StorageAccessException;
use Jackardios\ImageDimensions\Exceptions\TemporaryFileException;
use Jackardios\ImageDimensions\Exceptions\UrlAccessException;

interface ImageDimensions
{
    /**
     * Get image dimensions from a local file path.
     *
     * @throws FileNotFoundException
     * @throws InvalidImageException
     */
    public function fromLocal(string $path): Dimensions;

    /**
     * Get image dimensions from an HTTP/HTTPS URL.
     *
     * @throws UrlAccessException
     * @throws TemporaryFileException
     * @throws InvalidImageException
     */
    public function fromUrl(string $url): Dimensions;

    /**
     * Get image dimensions from a file on a Laravel Storage disk.
     *
     * @throws FileNotFoundException
     * @throws StorageAccessException
     * @throws TemporaryFileException
     * @throws InvalidImageException
     */
    public function fromStorage(string $diskName, string $path): Dimensions;
}
