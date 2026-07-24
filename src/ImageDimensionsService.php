<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Jackardios\ImageDimensions\Contracts\ImageDimensions as ImageDimensionsContract;
use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\Exceptions\FileTooLargeException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\StorageAccessException;
use Jackardios\ImageDimensions\Exceptions\TemporaryFileException;
use Jackardios\ImageDimensions\Exceptions\UrlAccessException;
use Jackardios\ImageDimensions\Support\SvgDimensionsExtractor;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Throwable;

class ImageDimensionsService implements ImageDimensionsContract
{
    protected int $remoteReadBytes;
    protected string $tempDir;
    protected bool $enableCache;
    protected int $cacheTtl;
    protected int $svgMaxFileSize;
    /** @var array{timeout: int, connect_timeout: int, verify: bool} */
    protected array $httpOptions;
    protected SvgDimensionsExtractor $svgExtractor;

    /**
     * @param array<string, mixed>|null $config Package config. When null, falls
     *        back to the global `image-dimensions` config. Values are captured
     *        at construction time.
     */
    public function __construct(?array $config = null)
    {
        $config ??= config('image-dimensions', []);

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
        $this->svgExtractor = new SvgDimensionsExtractor();
    }

    /**
     * Get image dimensions from a local file.
     *
     * @throws FileNotFoundException
     * @throws InvalidImageException
     */
    public function fromLocal(string $path): Dimensions
    {
        $path = trim($path);

        if ($path === '') {
            throw new InvalidImageException("Path must be a non-empty string");
        }

        // Resolve real path to handle symlinks and relative paths
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath)) {
            throw FileNotFoundException::forLocal($path);
        }

        if (!is_readable($realPath)) {
            throw new InvalidImageException("File is not readable: {$path}");
        }

        $modifiedTime = @filemtime($realPath);
        $cacheKey = $this->getCacheKey('local', $realPath, $modifiedTime === false ? null : $modifiedTime);

        return $this->getCachedOrCompute($cacheKey, function () use ($realPath) {
            return $this->analyzeFile($realPath, $realPath);
        });
    }

    /**
     * Get image dimensions from a URL.
     *
     * @throws TemporaryFileException
     * @throws UrlAccessException
     * @throws InvalidImageException
     */
    public function fromUrl(string $url): Dimensions
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
     * @throws FileNotFoundException
     * @throws TemporaryFileException
     * @throws StorageAccessException
     * @throws InvalidImageException
     */
    public function fromStorage(string $diskName, string $path): Dimensions
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

            $nameHint = parse_url($url, PHP_URL_PATH) ?: null;

            try {
                // Try with partial content first
                $this->readStreamToFile($stream, $tempFile, $this->remoteReadBytes);
                return $this->analyzeFile($tempFile, $nameHint);
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

                return $this->analyzeFile($tempFile, $nameHint);
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
                return $this->analyzeFile($tempFile, $path);
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

                return $this->analyzeFile($tempFile, $path);
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
     * Determine image dimensions for a file already on the local filesystem.
     *
     * @param string $path Filesystem path to read.
     * @param string|null $nameHint Original name/path used for extension-based
     *        format detection and error messages. Needed because remote sources
     *        are written to extension-less temp files.
     * @return array{width: int, height: int}
     * @throws InvalidImageException
     * @throws FileTooLargeException
     */
    protected function analyzeFile(string $path, ?string $nameHint = null): array
    {
        $label = $nameHint ?? $path;

        $fileSize = @filesize($path);
        if ($fileSize === false || $fileSize === 0) {
            throw InvalidImageException::forPath($label, 'File is empty.');
        }

        if ($this->looksLikeSvg($path, $nameHint)) {
            if ($this->svgMaxFileSize > 0 && $fileSize > $this->svgMaxFileSize) {
                throw FileTooLargeException::forSvg($this->svgMaxFileSize);
            }

            $content = @file_get_contents($path);
            if ($content === false) {
                throw InvalidImageException::forPath($label, 'Could not read SVG file.');
            }

            return $this->svgExtractor->extract($content)->toArray();
        }

        $size = @getimagesize($path);
        if ($size === false || (int) $size[0] <= 0 || (int) $size[1] <= 0) {
            throw InvalidImageException::forPath($label, 'Could not determine image dimensions');
        }

        return ['width' => (int) $size[0], 'height' => (int) $size[1]];
    }

    /**
     * Decide whether a file should be treated as SVG, using (in order) the
     * hinted extension, the detected MIME type, and a content sniff. The sniff
     * covers remote SVGs stored in extension-less temp files with no reliable
     * MIME type.
     */
    protected function looksLikeSvg(string $path, ?string $nameHint): bool
    {
        $extension = strtolower(pathinfo($nameHint ?? $path, PATHINFO_EXTENSION));
        if ($extension === 'svg') {
            return true;
        }

        $mimeType = @mime_content_type($path) ?: '';
        if (str_contains($mimeType, 'svg')) {
            return true;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $head = @fread($handle, 4096);
        @fclose($handle);

        return $head !== false && $head !== '' && SvgDimensionsExtractor::sniff($head);
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
     * The callback returns a primitive `array{width, height}` so cache stores
     * hold plain data (v1-compatible); the result is hydrated into a
     * {@see Dimensions} value object before returning.
     *
     * @param callable(): array{width: int, height: int} $callback
     */
    protected function getCachedOrCompute(string $key, callable $callback): Dimensions
    {
        if (!$this->enableCache) {
            return Dimensions::fromArray($callback());
        }

        return Dimensions::fromArray(Cache::remember($key, $this->cacheTtl, $callback));
    }
}