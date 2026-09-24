<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Contracts;

use Jackardios\ImageDimensions\Dimensions;
use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\StorageAccessException;
use Jackardios\ImageDimensions\Exceptions\TemporaryFileException;
use Jackardios\ImageDimensions\Exceptions\UrlAccessException;
use SplFileInfo;

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

    /**
     * Get image dimensions from raw binary image contents.
     *
     * @throws TemporaryFileException
     * @throws InvalidImageException
     */
    public function fromContents(string $contents): Dimensions;

    /**
     * Get image dimensions from an open, readable stream resource.
     *
     * A seekable stream is read from its start, whatever its position, and
     * left at the position it had. Any other stream is read from where it is.
     *
     * @param  resource  $stream
     *
     * @throws TemporaryFileException
     * @throws InvalidImageException
     */
    public function fromStream($stream): Dimensions;

    /**
     * Get image dimensions from an uploaded file or any SplFileInfo.
     *
     * @throws FileNotFoundException
     * @throws InvalidImageException
     */
    public function fromUploadedFile(SplFileInfo $file): Dimensions;

    /** Non-throwing variant of {@see fromLocal()}. */
    public function tryFromLocal(string $path): ?Dimensions;

    /** Non-throwing variant of {@see fromUrl()}. */
    public function tryFromUrl(string $url): ?Dimensions;

    /** Non-throwing variant of {@see fromStorage()}. */
    public function tryFromStorage(string $diskName, string $path): ?Dimensions;

    /** Non-throwing variant of {@see fromContents()}. */
    public function tryFromContents(string $contents): ?Dimensions;

    /**
     * Non-throwing variant of {@see fromStream()}.
     *
     * @param  resource  $stream
     */
    public function tryFromStream($stream): ?Dimensions;

    /** Non-throwing variant of {@see fromUploadedFile()}. */
    public function tryFromUploadedFile(SplFileInfo $file): ?Dimensions;
}
