<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions;

use DOMDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\StorageAccessException;
use Jackardios\ImageDimensions\Exceptions\TemporaryFileException;
use Jackardios\ImageDimensions\Exceptions\UrlAccessException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Throwable;

class ImageDimensionsService
{
    protected int $remoteReadBytes;
    protected string $tempDir;
    protected bool $enableCache;
    protected int $cacheTtl;
    protected array $supportedFormats;

    public function __construct()
    {
        $config = config("image-dimensions", []);
        $this->remoteReadBytes = (int) ($config['remote_read_bytes'] ?? 65536);
        $this->tempDir = $config['temp_dir'] ?? sys_get_temp_dir();
        $this->enableCache = (bool) ($config['enable_cache'] ?? true);
        $this->cacheTtl = (int) ($config['cache_ttl'] ?? 3600);
        $this->supportedFormats = $config['supported_formats'] ?? [
            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'avif'
        ];
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
            return $this->getDimensionsFromRemoteSource(
                fn() => $this->getStreamFromUrl($url),
                fn() => $this->getFullContentFromUrl($url)
            );
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

        $adapter = $disk->getAdapter();
        if ($adapter instanceof LocalFilesystemAdapter) {
            return $this->fromLocal($disk->path($path));
        }

        $cacheKey = $this->getCacheKey('storage', "{$diskName}:{$path}", $disk->lastModified($path));

        return $this->getCachedOrCompute($cacheKey, function () use ($disk, $path) {
            return $this->getDimensionsFromRemoteSource(
                function() use ($disk, $path) {
                    $stream = $disk->readStream($path);
                    if ($stream === false || !is_resource($stream)) {
                        throw StorageAccessException::couldNotReadStream($path);
                    }
                    return $stream;
                },
                function() use ($disk, $path) {
                    $content = $disk->get($path);
                    if ($content === null) {
                        throw StorageAccessException::couldNotReadFullContent($path);
                    }
                    return $content;
                }
            );
        });
    }

    /**
     * @param string $url
     * @return resource
     * @throws UrlAccessException
     */
    protected function getStreamFromUrl(string $url)
    {
        try {
            $response = Http::withOptions(['stream' => true])
                ->timeout(30)
                ->get($url);

            if ($response->failed()) {
                throw UrlAccessException::couldNotOpen($url);
            }

            return $response->toPsrResponse()->getBody()->detach();
        } catch (Throwable $e) {
            throw UrlAccessException::couldNotOpen($url, $e);
        }
    }

    /**
     * @param string $url
     * @return string
     * @throws UrlAccessException
     */
    protected function getFullContentFromUrl(string $url): string
    {
        try {
            $response = Http::timeout(30)->get($url);

            if ($response->failed()) {
                throw UrlAccessException::couldNotDownload($url);
            }

            return $response->body();
        } catch (Throwable $e) {
            throw UrlAccessException::couldNotDownload($url, $e);
        }
    }

    /**
     * @param callable(): resource $streamFactory
     * @param callable(): string $contentFactory
     * @return array{width: int, height: int}
     * @throws TemporaryFileException
     * @throws InvalidImageException
     */
    protected function getDimensionsFromRemoteSource(callable $streamFactory, callable $contentFactory): array
    {
        $tempFile = $this->createTempFile('remote');
        $stream = null;

        try {
            // First, try with partial read
            try {
                $stream = $streamFactory();
                $this->readStreamToFile($stream, $tempFile, $this->remoteReadBytes);
                return $this->getDimensionsFromPath($tempFile);
            } catch (InvalidImageException $e) {
                // If partial read failed, read entire file
                if (is_resource($stream)) {
                    fclose($stream);
                }

                $fullContent = $contentFactory();
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
                @unlink($tempFile);
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
        $fileSize = @filesize($filePath);
        if (! $fileSize) {
            throw InvalidImageException::forPath($filePath, "File is empty.");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = mime_content_type($filePath) ?: '';

        // Handle SVG separately
        if ($extension === 'svg' || str_contains($mimeType, 'svg')) {
            return $this->getSvgDimensions($filePath);
        }

        // Use getimagesize for raster images
        $size = @getimagesize($filePath);
        if ($size === false) {
            if (!empty($this->supportedFormats) && !in_array($extension, $this->supportedFormats, true)) {
                throw new InvalidImageException("Unsupported image format: {$extension}");
            }
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
                throw InvalidImageException::forPath($filePath, "Invalid XML content.");
            }

            $svg = $doc->documentElement;
            if (!$svg || $svg->tagName !== 'svg') {
                throw new InvalidImageException("Invalid SVG file: {$filePath}");
            }

            $width = $this->parseSvgDimension($svg->getAttribute('width'));
            $height = $this->parseSvgDimension($svg->getAttribute('height'));

            if (($width === null || $height === null) && $svg->hasAttribute('viewBox')) {
                $viewBox = preg_split('/[\s,]+/', trim($svg->getAttribute('viewBox')));
                if (count($viewBox) === 4) {
                    $width = $width ?? (int) ceil((float) $viewBox[2]);
                    $height = $height ?? (int) ceil((float) $viewBox[3]);
                }
            }

            if ($width === null || $height === null || $width <= 0 || $height <= 0) {
                throw new InvalidImageException("Could not determine SVG dimensions: {$filePath}");
            }

            return ['width' => $width, 'height' => $height];
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
        if (preg_match('/^(\d+(?:\.\d+)?)(px)?$/i', $value, $matches)) {
            $number = (float) $matches[1];
            return (int) ceil($number);
        }

        return null;
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