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
    protected int $svgMaxFileSize;
    protected array $httpOptions;

    public function __construct()
    {
        $config = config("image-dimensions", []);

        $this->remoteReadBytes = max(8192, min(1048576, (int) ($config['remote_read_bytes'] ?? 131072))); // 8KB-1MB
        $this->tempDir = $config['temp_dir'] ?? sys_get_temp_dir();
        $this->enableCache = (bool) ($config['enable_cache'] ?? true);
        $this->cacheTtl = max(0, (int) ($config['cache_ttl'] ?? 3600));
        $this->svgMaxFileSize = max(0, (int) ($config['svg']['max_file_size'] ?? 10485760));
        $this->httpOptions = [
            'timeout' => max(0, (int) ($config['http']['timeout'] ?? 60)),
            'connect_timeout' => max(0, (int) ($config['http']['connect_timeout'] ?? 10)),
            'verify' => (bool) ($config['http']['verify_ssl'] ?? true),
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
        $path = trim($path);

        if ($path === '') {
            throw new InvalidImageException("Path must be a non-empty string");
        }

        // Resolve real path to handle symlinks and relative paths
        $realPath = realpath($path);
        if ($realPath === false || !file_exists($realPath)) {
            throw FileNotFoundException::forLocal($path);
        }

        if (!is_readable($realPath)) {
            throw new InvalidImageException("File is not readable: {$path}");
        }

        $cacheKey = $this->getCacheKey('local', $realPath, filemtime($realPath));

        return $this->getCachedOrCompute($cacheKey, function () use ($realPath) {
            return $this->getDimensionsFromPath($realPath);
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
        $url = trim($url);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidImageException("Invalid URL provided: {$url}");
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidImageException("Only HTTP and HTTPS URLs are supported");
        }

        $cacheKey = $this->getCacheKey('url', $url);

        return $this->getCachedOrCompute($cacheKey, function () use ($url) {
            return $this->getDimensionsFromUrl($url);
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
        $diskName = trim($diskName);
        $path = trim($path);

        if ($diskName === '') {
            throw new InvalidImageException("Disk name must be a non-empty string");
        }

        if ($path === '') {
            throw new InvalidImageException("Path must be a non-empty string");
        }

        try {
            $disk = Storage::disk($diskName);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidImageException("Storage disk '{$diskName}' does not exist");
        }

        if (!$disk->exists($path)) {
            throw FileNotFoundException::forStorage($diskName, $path);
        }

        $adapter = $disk->getAdapter();
        if ($adapter instanceof LocalFilesystemAdapter) {
            return $this->fromLocal($disk->path($path));
        }

        $cacheKey = $this->getCacheKey('storage', "{$diskName}:{$path}", $disk->lastModified($path));

        return $this->getCachedOrCompute($cacheKey, function () use ($disk, $path) {
            return $this->getDimensionsFromStorage($disk, $path);
        });
    }

    /**
     * Get dimensions from URL with optimized downloading
     * @throws TemporaryFileException
     * @throws UrlAccessException
     */
    protected function getDimensionsFromUrl(string $url): array
    {
        $tempFile = $this->createTempFile('url');
        $stream = null;

        try {
            // First attempt with partial read
            $response = Http::withOptions([
                ...$this->httpOptions,
                'stream' => true,
            ])->get($url);

            if ($response->failed()) {
                throw UrlAccessException::couldNotOpen($url);
            }

            $stream = $response->toPsrResponse()->getBody()->detach();
            if (!is_resource($stream)) {
                throw UrlAccessException::couldNotOpen($url);
            }

            try {
                // Try with partial content first
                $this->readStreamToFile($stream, $tempFile, $this->remoteReadBytes);
                return $this->getDimensionsFromPath($tempFile);
            } catch (InvalidImageException) {
                // If partial read failed, download full file
                if (is_resource($stream)) {
                    fclose($stream);
                }

                $fullResponse = Http::withOptions($this->httpOptions)->get($url);
                if ($fullResponse->failed()) {
                    throw UrlAccessException::couldNotDownload($url);
                }

                $content = $fullResponse->body();
                if (file_put_contents($tempFile, $content) === false) {
                    throw TemporaryFileException::couldNotWrite();
                }

                return $this->getDimensionsFromPath($tempFile);
            } catch (Throwable $e) {
                throw UrlAccessException::couldNotDownload($url, $e);
            }
        } catch (UrlAccessException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw UrlAccessException::couldNotOpen($url, $e);
        } finally {
            if (is_resource($stream)) {
                @fclose($stream);
            }
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Get dimensions from storage with streaming support
     * @throws StorageAccessException
     * @throws TemporaryFileException
     * @throws InvalidImageException
     */
    protected function getDimensionsFromStorage($disk, string $path): array
    {
        $tempFile = $this->createTempFile('storage');
        $stream = null;

        try {
            $stream = $disk->readStream($path);
            if (!is_resource($stream)) {
                throw StorageAccessException::couldNotReadStream($path);
            }

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
                @fclose($stream);
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
        if ($fileSize === false || $fileSize === 0) {
            throw InvalidImageException::forPath($filePath, "File is empty.");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = mime_content_type($filePath) ?: '';

        // Handle SVG separately
        if ($extension === 'svg' || str_contains($mimeType, 'svg')) {
            if ($fileSize > $this->svgMaxFileSize) {
                throw new InvalidImageException("SVG file is too large (max " . $this->svgMaxFileSize . " bytes)");
            }
            return $this->getSvgDimensions($filePath);
        }

        $size = @getimagesize($filePath);
        if ($size === false) {
            throw InvalidImageException::forPath($filePath, "Could not determine image dimensions");
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

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $previousEntityLoader = libxml_disable_entity_loader(true);

        try {
            $doc = new DOMDocument();
            $content = $this->sanitizeSvgContent($content);

            if (!$doc->loadXML($content, LIBXML_NONET | LIBXML_NOENT)) {
                $errors = libxml_get_errors();
                $errorMessage = !empty($errors) ? $errors[0]->message : "Invalid XML content";
                throw InvalidImageException::forPath($filePath, $errorMessage);
            }

            $svg = $doc->documentElement;
            if (!$svg || $svg->tagName !== 'svg') {
                throw new InvalidImageException("Invalid SVG file: {$filePath}");
            }

            $width = $this->parseSvgDimension($svg->getAttribute('width'));
            $height = $this->parseSvgDimension($svg->getAttribute('height'));

            // Try viewBox if dimensions are not set or invalid
            if (($width === null || $height === null) && $svg->hasAttribute('viewBox')) {
                $viewBox = preg_split('/[\s,]+/', trim($svg->getAttribute('viewBox')));
                if (count($viewBox) === 4) {
                    $viewBoxWidth = abs((float) $viewBox[2] - (float) $viewBox[0]);
                    $viewBoxHeight = abs((float) $viewBox[3] - (float) $viewBox[1]);

                    $width = $width ?? (int) ceil($viewBoxWidth);
                    $height = $height ?? (int) ceil($viewBoxHeight);
                }
            }

            if ($width === null || $height === null || $width <= 0 || $height <= 0) {
                throw new InvalidImageException("Could not determine SVG dimensions: {$filePath}");
            }

            return ['width' => $width, 'height' => $height];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
            libxml_disable_entity_loader($previousEntityLoader);
        }
    }

    /**
     * Sanitize SVG content to remove potentially dangerous elements
     */
    protected function sanitizeSvgContent(string $content): string
    {
        // Remove script tags
        $content = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $content);

        // Remove on* event attributes
        $content = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $content);

        // Remove external references in DOCTYPE
        $content = preg_replace('/<!DOCTYPE[^>]*>/i', '', $content);

        return $content;
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

        if ($value === '' || $value === 'auto') {
            return null;
        }

        if (str_ends_with($value, '%')) {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(px)?$/i', $value, $matches)) {
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
        $handle = @fopen($filePath, 'wb');
        if ($handle === false) {
            throw TemporaryFileException::couldNotWrite();
        }

        try {
            $bytesRead = 0;
            $emptyReads = 0;
            $maxEmptyReads = 3;

            while (!feof($stream) && $bytesRead < $maxBytes && $emptyReads < $maxEmptyReads) {
                $remaining = $maxBytes - $bytesRead;
                $chunkSize = min(8192, $remaining);

                $chunk = @fread($stream, $chunkSize);
                if ($chunk === false) {
                    break;
                }

                if ($chunk === '') {
                    $emptyReads++;
                    usleep(1000);
                    continue;
                }

                $emptyReads = 0;

                $written = @fwrite($handle, $chunk);
                if ($written === false) {
                    throw TemporaryFileException::couldNotWrite();
                }

                $bytesRead += $written;

                if ($written < strlen($chunk)) {
                    break;
                }
            }

            if ($bytesRead === 0) {
                throw TemporaryFileException::couldNotWrite();
            }
        } finally {
            @fclose($handle);
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
        if (!is_dir($this->tempDir) || !is_writable($this->tempDir)) {
            throw TemporaryFileException::couldNotCreate();
        }

        $maxAttempts = 3;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $tempFile = @tempnam($this->tempDir, "imgdim_{$prefix}_");
            if ($tempFile !== false) {
                return $tempFile;
            }
            usleep(10000); // 10ms
        }

        throw TemporaryFileException::couldNotCreate();
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