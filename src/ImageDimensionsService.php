<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions;

use Illuminate\Support\Facades\Storage;
use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\Exceptions\ImageDimensionsException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\StorageAccessException;
use Jackardios\ImageDimensions\Exceptions\TemporaryFileException;
use Jackardios\ImageDimensions\Exceptions\UrlAccessException;

class ImageDimensionsService
{
    protected int $remoteReadBytes;
    protected string $tempDir;

    public function __construct()
    {
        $this->remoteReadBytes = config("image-dimensions.remote_read_bytes", 65536);
        $this->tempDir = config("image-dimensions.temp_dir", sys_get_temp_dir());
    }

    /**
     * Get image dimensions from a file path.
     *
     * @param string $filePath
     * @return array<string, int>
     * @throws InvalidImageException
     */
    protected function getDimensionsFromPath(string $filePath): array
    {
        $size = @getimagesize($filePath);

        if ($size === false) {
            throw InvalidImageException::forPath($filePath);
        }

        return ["width" => $size[0], "height" => $size[1]];
    }

    /**
     * Read a portion of a stream into a temporary file.
     *
     * @param resource $streamHandle
     * @param string $tempFilePath
     * @param int $bytesToRead
     * @return void
     * @throws TemporaryFileException
     */
    protected function readStreamToTempFile($streamHandle, string $tempFilePath, int $bytesToRead): void
    {
        $tempFileHandle = fopen($tempFilePath, "w");
        if ($tempFileHandle === false) {
            throw TemporaryFileException::couldNotWrite();
        }

        $bytesRead = 0;
        while (!feof($streamHandle) && $bytesRead < $bytesToRead) {
            $chunk = fread($streamHandle, 4096);
            if ($chunk === false) {
                break;
            }
            fwrite($tempFileHandle, $chunk);
            $bytesRead += strlen($chunk);
        }

        fclose($tempFileHandle);
    }

    /**
     * Get image dimensions from a local file.
     *
     * @param string $path
     * @return array<string, int>
     * @throws FileNotFoundException
     * @throws InvalidImageException
     */
    public function fromLocal(string $path): array
    {
        if (!file_exists($path)) {
            throw FileNotFoundException::forLocal($path);
        }

        return $this->getDimensionsFromPath($path);
    }

    /**
     * Get image dimensions from a URL.
     *
     * @param string $url
     * @return array<string, int>
     * @throws TemporaryFileException
     * @throws UrlAccessException
     * @throws InvalidImageException
     */
    public function fromUrl(string $url): array
    {
        $tempFile = tempnam($this->tempDir, "imgdim_url_");
        if ($tempFile === false) {
            throw TemporaryFileException::couldNotCreate();
        }

        try {
            $handle = @fopen($url, "r");
            if ($handle === false) {
                throw UrlAccessException::couldNotOpen($url);
            }

            try {
                $this->readStreamToTempFile($handle, $tempFile, $this->remoteReadBytes);
                $dimensions = $this->getDimensionsFromPath($tempFile);
            } catch (ImageDimensionsException $e) {
                // Fallback: try to download the whole file if initial read fails
                $fullContent = @file_get_contents($url);
                if ($fullContent === false) {
                    throw UrlAccessException::couldNotDownload($url);
                }
                file_put_contents($tempFile, $fullContent);
                $dimensions = $this->getDimensionsFromPath($tempFile);
            }
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        return $dimensions;
    }

    /**
     * Get image dimensions from a Laravel Storage file.
     *
     * @param string $diskName
     * @param string $path
     * @return array<string, int>
     * @throws FileNotFoundException
     * @throws TemporaryFileException
     * @throws StorageAccessException
     * @throws InvalidImageException
     */
    public function fromStorage(string $diskName, string $path): array
    {
        $disk = Storage::disk($diskName);

        if (!$disk->exists($path)) {
            throw FileNotFoundException::forStorage($diskName, $path);
        }

        $tempFile = tempnam($this->tempDir, "imgdim_storage_");
        if ($tempFile === false) {
            throw TemporaryFileException::couldNotCreate();
        }

        try {
            $stream = $disk->readStream($path);
            if ($stream === false) {
                throw StorageAccessException::couldNotReadStream($path);
            }

            try {
                $this->readStreamToTempFile($stream, $tempFile, $this->remoteReadBytes);
                $dimensions = $this->getDimensionsFromPath($tempFile);
            } catch (ImageDimensionsException $e) {
                // Fallback for storage: read full content if initial read fails
                $fullContent = $disk->get($path);
                if ($fullContent === false) {
                    throw StorageAccessException::couldNotReadFullContent($path);
                }
                file_put_contents($tempFile, $fullContent);
                $dimensions = $this->getDimensionsFromPath($tempFile);
            }
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        return $dimensions;
    }
}


