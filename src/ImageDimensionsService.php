<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions;

use DOMDocument;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\StorageAccessException;
use Jackardios\ImageDimensions\Exceptions\TemporaryFileException;
use Jackardios\ImageDimensions\Exceptions\UrlAccessException;
use League\Flysystem\Local\LocalFilesystemAdapter;

class ImageDimensionsService
{
    protected int $remoteReadBytes;
    protected string $tempDir;
    protected bool $enableCache;
    protected int $cacheTtl;
    protected array $supportedFormats;

    public function __construct()
    {
        $this->remoteReadBytes = (int) config("image-dimensions.remote_read_bytes", 65536);
        $this->tempDir = config("image-dimensions.temp_dir", sys_get_temp_dir());
        $this->enableCache = (bool) config("image-dimensions.enable_cache", true);
        $this->cacheTtl = (int) config("image-dimensions.cache_ttl", 3600);
        $this->supportedFormats = config("image-dimensions.supported_formats", [
            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'avif'
        ]);
    }

    /**
     * Get image dimensions from a local file.
     *
     * @param string $path
     * @return array{width: int, height: int}
     * @throws FileNotFoundException
     * @throws InvalidImageException
     */
    public function fromLocal(string $path): array
    {
        if (trim($path) === '') {
            throw new InvalidImageException("Path must be a non-empty string");
        }

        if (!file_exists($path)) {
            throw FileNotFoundException::forLocal($path);
        }

        if (!is_readable($path)) {
            throw new InvalidImageException("File is not readable: {$path}");
        }

        $cacheKey = $this->getCacheKey('local', $path, filemtime($path));

        return $this->getCachedOrCompute($cacheKey, function () use ($path) {
            return $this->getDimensionsFromPath($path);
        });
    }

    /**
     * Get image dimensions from a URL.
     *
     * @param string $url
     * @return array{width: int, height: int}
     * @throws TemporaryFileException
     * @throws UrlAccessException
     * @throws InvalidImageException
     */
    public function fromUrl(string $url): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidImageException("Invalid URL provided: {$url}");
        }

        $cacheKey = $this->getCacheKey('url', $url);

        return $this->getCachedOrCompute($cacheKey, function () use ($url) {
            return $this->fetchAndGetDimensions($url);
        });
    }

    /**
     * Get image dimensions from a Laravel Storage file.
     *
     * @param string $diskName
     * @param string $path
     * @return array{width: int, height: int}
     * @throws FileNotFoundException
     * @throws TemporaryFileException
     * @throws StorageAccessException
     * @throws InvalidImageException
     */
    public function fromStorage(string $diskName, string $path): array
    {
        if (trim($diskName) === '') {
            throw new InvalidImageException("Disk name must be a non-empty string");
        }

        if (trim($path) === '') {
            throw new InvalidImageException("Path must be a non-empty string");
        }

        $disk = Storage::disk($diskName);

        if (!$disk->exists($path)) {
            throw FileNotFoundException::forStorage($diskName, $path);
        }

        // For local disks, use direct file access
        $adapter = $disk->getAdapter();
        if ($adapter instanceof LocalFilesystemAdapter) {
            $localPath = $disk->path($path);
            if (file_exists($localPath)) {
                return $this->fromLocal($localPath);
            }
        }

        $cacheKey = $this->getCacheKey('storage', "{$diskName}:{$path}", $disk->lastModified($path));

        return $this->getCachedOrCompute($cacheKey, function () use ($disk, $path) {
            return $this->fetchFromStorageAndGetDimensions($disk, $path);
        });
    }

    /**
     * Fetch content from URL and get dimensions.
     *
     * @param string $url
     * @return array{width: int, height: int}
     * @throws TemporaryFileException
     * @throws UrlAccessException
     * @throws InvalidImageException
     */
    protected function fetchAndGetDimensions(string $url): array
    {
        $tempFile = $this->createTempFile('url');
        $handle = null;

        try {
            // Try to open URL stream
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'ImageDimensions/1.0',
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $handle = fopen($url, 'rb', false, $context);
            if ($handle === false) {
                throw UrlAccessException::couldNotOpen($url);
            }

            // First, try with partial read for efficiency
            try {
                $this->readStreamToFile($handle, $tempFile, $this->remoteReadBytes);
                return $this->getDimensionsFromPath($tempFile);
            } catch (InvalidImageException $e) {
                // If partial read failed, download entire file
                fclose($handle);
                $handle = null;

                $fullContent = file_get_contents($url, false, $context);
                if ($fullContent === false) {
                    throw UrlAccessException::couldNotDownload($url);
                }

                if (file_put_contents($tempFile, $fullContent) === false) {
                    throw TemporaryFileException::couldNotWrite();
                }

                return $this->getDimensionsFromPath($tempFile);
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Fetch content from storage and get dimensions.
     *
     * @param Filesystem $disk
     * @param string $path
     * @return array{width: int, height: int}
     * @throws TemporaryFileException
     * @throws StorageAccessException
     * @throws InvalidImageException
     */
    protected function fetchFromStorageAndGetDimensions(Filesystem $disk, string $path): array
    {
        $tempFile = $this->createTempFile('storage');
        $stream = null;

        try {
            // Try to read stream
            $stream = $disk->readStream($path);
            if ($stream === false) {
                throw StorageAccessException::couldNotReadStream($path);
            }

            // First, try with partial read
            try {
                $this->readStreamToFile($stream, $tempFile, $this->remoteReadBytes);
                return $this->getDimensionsFromPath($tempFile);
            } catch (InvalidImageException $e) {
                // If partial read failed, read entire file
                if (is_resource($stream)) {
                    fclose($stream);
                }

                $fullContent = $disk->get($path);
                if ($fullContent === null) {
                    throw StorageAccessException::couldNotReadFullContent($path);
                }

                if (file_put_contents($tempFile, $fullContent) === false) {
                    throw TemporaryFileException::couldNotWrite();
                }

                return $this->getDimensionsFromPath($tempFile);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Get image dimensions from a file path.
     *
     * @param string $filePath
     * @return array{width: int, height: int}
     * @throws InvalidImageException
     */
    protected function getDimensionsFromPath(string $filePath): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Check if format is supported
        if (!empty($this->supportedFormats) && !in_array($extension, $this->supportedFormats, true)) {
            // Try to detect by mime type
            $mimeType = mime_content_type($filePath);
            if (!$this->isSupportedMimeType($mimeType)) {
                throw new InvalidImageException("Unsupported image format: {$extension}");
            }
        }

        // Handle SVG separately
        if ($extension === 'svg' || mime_content_type($filePath) === 'image/svg+xml') {
            return $this->getSvgDimensions($filePath);
        }

        // Use getimagesize for raster images
        $size = @getimagesize($filePath);
        if ($size === false) {
            throw InvalidImageException::forPath($filePath);
        }

        return [
            'width' => (int) $size[0],
            'height' => (int) $size[1]
        ];
    }

    /**
     * Get SVG dimensions from file.
     *
     * @param string $filePath
     * @return array{width: int, height: int}
     * @throws InvalidImageException
     */
    protected function getSvgDimensions(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw InvalidImageException::forPath($filePath);
        }

        // Try to parse SVG
        $previousUseInternalErrors = libxml_use_internal_errors(true);

        try {
            $doc = new DOMDocument();
            if (!$doc->loadXML($content)) {
                throw InvalidImageException::forPath($filePath);
            }

            $svg = $doc->documentElement;
            if (!$svg || $svg->tagName !== 'svg') {
                throw new InvalidImageException("Invalid SVG file: {$filePath}");
            }

            $width = null;
            $height = null;

            // First, try to get dimensions from width/height attributes
            if ($svg->hasAttribute('width') && $svg->hasAttribute('height')) {
                $width = $this->parseSvgDimension($svg->getAttribute('width'));
                $height = $this->parseSvgDimension($svg->getAttribute('height'));
            }

            // If no dimensions, try viewBox
            if (($width === null || $height === null) && $svg->hasAttribute('viewBox')) {
                $viewBox = preg_split('/[\s,]+/', trim($svg->getAttribute('viewBox')));
                if (count($viewBox) === 4) {
                    $width = (int) ceil((float) $viewBox[2]);
                    $height = (int) ceil((float) $viewBox[3]);
                }
            }

            if ($width === null || $height === null || $width <= 0 || $height <= 0) {
                throw new InvalidImageException("Could not determine SVG dimensions: {$filePath}");
            }

            return [
                'width' => $width,
                'height' => $height
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    /**
     * Parse SVG dimension value.
     *
     * @param string $value
     * @return int|null
     */
    protected function parseSvgDimension(string $value): ?int
    {
        $value = trim($value);
        if (preg_match('/^(\d+(?:\.\d+)?)(px|pt|em|%)?$/i', $value, $matches)) {
            $number = (float) $matches[1];
            $unit = $matches[2] ?? 'px';

            return match (strtolower($unit)) {
                'pt' => (int)ceil($number * 1.333),
                'em' => (int)ceil($number * 16),
                '%' => null,
                default => (int)ceil($number),
            };
        }

        return null;
    }

    /**
     * Check if MIME type is supported.
     *
     * @param bool|string $mimeType
     * @return bool
     */
    protected function isSupportedMimeType(bool|string $mimeType): bool
    {
        if ($mimeType === false) {
            return false;
        }

        $supportedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/bmp',
            'image/webp',
            'image/svg+xml',
            'image/x-icon',
            'image/vnd.microsoft.icon',
            'image/avif',
        ];

        return in_array($mimeType, $supportedMimeTypes, true);
    }

    /**
     * Read stream to file.
     *
     * @param resource $stream
     * @param string $filePath
     * @param int $maxBytes
     * @throws TemporaryFileException
     */
    protected function readStreamToFile($stream, string $filePath, int $maxBytes): void
    {
        $handle = fopen($filePath, 'wb');
        if ($handle === false) {
            throw TemporaryFileException::couldNotWrite();
        }

        try {
            $bytesRead = 0;
            while (!feof($stream) && $bytesRead < $maxBytes) {
                $chunk = fread($stream, min(8192, $maxBytes - $bytesRead));
                if ($chunk === false) {
                    break;
                }

                $written = fwrite($handle, $chunk);
                if ($written === false) {
                    throw TemporaryFileException::couldNotWrite();
                }

                $bytesRead += strlen($chunk);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Create temporary file.
     *
     * @param string $prefix
     * @return string
     * @throws TemporaryFileException
     */
    protected function createTempFile(string $prefix): string
    {
        try {
            $tempFile = tempnam($this->tempDir, "imgdim_{$prefix}_");

            if ($tempFile === false) {
                throw TemporaryFileException::couldNotCreate();
            }

            return $tempFile;
        } catch (\Exception) {
            throw TemporaryFileException::couldNotCreate();
        }
    }

    /**
     * Get cache key.
     *
     * @param string $type
     * @param string $identifier
     * @param int|null $modifiedTime
     * @return string
     */
    protected function getCacheKey(string $type, string $identifier, ?int $modifiedTime = null): string
    {
        $key = "image_dimensions:{$type}:" . md5($identifier);
        if ($modifiedTime !== null) {
            $key .= ":{$modifiedTime}";
        }

        return $key;
    }

    /**
     * Get cached value or compute and cache.
     *
     * @param string $key
     * @param callable $callback
     * @return array{width: int, height: int}
     */
    protected function getCachedOrCompute(string $key, callable $callback): array
    {
        if (!$this->enableCache) {
            return $callback();
        }

        return Cache::remember($key, $this->cacheTtl, $callback);
    }
}