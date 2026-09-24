<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Jackardios\ImageDimensions\Contracts\ImageDimensions as ImageDimensionsContract;
use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\Exceptions\FileTooLargeException;
use Jackardios\ImageDimensions\Exceptions\ImageDimensionsException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\StorageAccessException;
use Jackardios\ImageDimensions\Exceptions\TemporaryFileException;
use Jackardios\ImageDimensions\Exceptions\UrlAccessException;
use Jackardios\ImageDimensions\Exceptions\UrlNotAllowedException;
use Jackardios\ImageDimensions\Support\SvgDimensionsExtractor;
use Jackardios\ImageDimensions\Support\TemporaryFile;
use Jackardios\ImageDimensions\Support\TransferStopped;
use Jackardios\ImageDimensions\Support\UrlGuard;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SplFileInfo;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

class ImageDimensionsService implements ImageDimensionsContract
{
    /** @var int<8192, 1048576> */
    protected int $remoteReadBytes;

    protected int $maxDownloadBytes;

    protected string $tempDir;

    protected bool $enableCache;

    protected ?int $cacheTtl;

    protected int $svgMaxFileSize;

    /** @var array{timeout: int, connect_timeout: int, verify: bool} */
    protected array $httpOptions;

    protected SvgDimensionsExtractor $svgExtractor;

    protected UrlGuard $urlGuard;

    /**
     * @param  array<string, mixed>|null  $config  Package config. When null, falls
     *                                             back to the global `image-dimensions` config. Values are captured
     *                                             at construction time.
     */
    public function __construct(?array $config = null)
    {
        $config ??= config('image-dimensions', []);

        $this->remoteReadBytes = max(8192, min(1048576, (int) ($config['remote_read_bytes'] ?? 131072))); // 8KB-1MB
        $maxDownloadBytes = (int) ($config['max_download_bytes'] ?? 33554432);
        // 0 means unlimited; otherwise never below the initial read size.
        $this->maxDownloadBytes = $maxDownloadBytes <= 0 ? 0 : max($maxDownloadBytes, $this->remoteReadBytes);
        $this->tempDir = $config['temp_dir'] ?? sys_get_temp_dir();
        $this->enableCache = (bool) ($config['enable_cache'] ?? true);
        // null => cache forever; <= 0 => do not cache; otherwise, seconds.
        $rawTtl = array_key_exists('cache_ttl', $config) ? $config['cache_ttl'] : 3600;
        $this->cacheTtl = $rawTtl === null ? null : (int) $rawTtl;
        $this->svgMaxFileSize = max(0, (int) ($config['svg']['max_file_size'] ?? 10485760));
        $this->httpOptions = [
            'timeout' => max(0, (int) ($config['http']['timeout'] ?? 60)),
            'connect_timeout' => max(0, (int) ($config['http']['connect_timeout'] ?? 10)),
            'verify' => (bool) ($config['http']['verify_ssl'] ?? true),
        ];
        $this->svgExtractor = new SvgDimensionsExtractor;

        $urlConfig = is_array($config['url'] ?? null) ? $config['url'] : [];
        $this->urlGuard = new UrlGuard(
            (bool) ($urlConfig['allow_private_hosts'] ?? false),
            is_array($urlConfig['allowed_hosts'] ?? null) ? array_values($urlConfig['allowed_hosts']) : [],
            (int) ($urlConfig['max_redirects'] ?? 5),
        );
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
            throw new InvalidImageException('Path must be a non-empty string');
        }

        // Resolve real path to handle symlinks and relative paths
        $realPath = realpath($path);
        if ($realPath === false || ! is_file($realPath)) {
            throw FileNotFoundException::forLocal($path);
        }

        if (! is_readable($realPath)) {
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
     * @throws UrlNotAllowedException
     * @throws InvalidImageException
     */
    public function fromUrl(string $url): Dimensions
    {
        $url = trim($url);

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidImageException("Invalid URL provided: {$url}");
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidImageException('Only HTTP and HTTPS URLs are supported');
        }

        // SSRF guard, first without DNS: a cache hit must not cost a lookup,
        // nor fail when DNS is down.
        $this->urlGuard->assertAllowed($url, resolve: false);

        $cacheKey = $this->getCacheKey('url', $url);

        return $this->getCachedOrCompute($cacheKey, function () use ($url) {
            // Resolved on every fetch: an earlier verdict may be stale.
            $this->urlGuard->assertAllowed($url);

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
            throw new InvalidImageException('Disk name must be a non-empty string');
        }

        if ($path === '') {
            throw new InvalidImageException('Path must be a non-empty string');
        }

        try {
            $disk = Storage::disk($diskName);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidImageException("Storage disk '{$diskName}' does not exist");
        }

        if (! $disk->exists($path)) {
            throw FileNotFoundException::forStorage($diskName, $path);
        }

        // Fast path: a local disk is just a filesystem path, so reuse fromLocal
        // (which also enables its extension-aware format detection and caching).
        if (method_exists($disk, 'getAdapter') && $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return $this->fromLocal($disk->path($path));
        }

        // The modification time invalidates the cache when the file changes;
        // some drivers cannot report it, in which case the key omits it.
        try {
            $modifiedTime = $disk->lastModified($path);
        } catch (Throwable) {
            $modifiedTime = null;
        }

        $cacheKey = $this->getCacheKey('storage', "{$diskName}:{$path}", $modifiedTime);

        return $this->getCachedOrCompute($cacheKey, function () use ($disk, $path) {
            return $this->getDimensionsFromStorage($disk, $path);
        });
    }

    /**
     * Get image dimensions from raw binary image contents.
     *
     * The result is not cached (there is no stable identity to key on).
     *
     * @throws TemporaryFileException
     * @throws InvalidImageException
     */
    public function fromContents(string $contents): Dimensions
    {
        if ($contents === '') {
            throw new InvalidImageException('Contents must be a non-empty string.');
        }

        if ($this->maxDownloadBytes > 0 && strlen($contents) > $this->maxDownloadBytes) {
            throw FileTooLargeException::forDownload($this->maxDownloadBytes);
        }

        $temp = new TemporaryFile($this->tempDir, 'imgdim_contents_');
        $temp->append($contents);

        return Dimensions::fromArray($this->analyzeFile($temp->path(), '(contents)'));
    }

    /**
     * Get image dimensions from an open, readable stream resource.
     *
     * The result is not cached. The stream is read but not closed — the caller
     * owns it.
     *
     * @param  resource  $stream
     *
     * @throws TemporaryFileException
     * @throws InvalidImageException
     */
    public function fromStream($stream): Dimensions
    {
        if (! is_resource($stream)) {
            throw new InvalidImageException('A readable stream resource is required.');
        }

        $temp = new TemporaryFile($this->tempDir, 'imgdim_stream_');
        $limit = $this->maxDownloadBytes;

        if ($limit > 0) {
            // Read one byte past the cap so an overflow is detectable.
            while ($temp->bytesWritten() <= $limit) {
                if ($temp->appendFromStream($stream, $limit + 1 - $temp->bytesWritten()) === 0) {
                    break;
                }
            }

            if ($temp->bytesWritten() > $limit) {
                throw FileTooLargeException::forDownload($limit);
            }
        } else {
            while ($temp->appendFromStream($stream, 1048576) > 0) {
                // keep reading until the stream is exhausted
            }
        }

        return Dimensions::fromArray($this->analyzeFile($temp->path(), '(stream)'));
    }

    /**
     * Get image dimensions from an uploaded file (Illuminate/Symfony UploadedFile
     * or any SplFileInfo).
     *
     * The result is deliberately NOT cached: PHP recycles upload temp names
     * (`/tmp/phpXXXXXX`) and filemtime only has one-second granularity, so a
     * path+mtime cache key can collide across two different uploads and return
     * another request's dimensions.
     *
     * @throws FileNotFoundException
     * @throws InvalidImageException
     */
    public function fromUploadedFile(SplFileInfo $file): Dimensions
    {
        $path = $file->getRealPath();
        if ($path === false || $path === '') {
            $path = $file->getPathname();
        }

        if ($path === '' || ! is_file($path)) {
            throw FileNotFoundException::forLocal($path);
        }

        if (! is_readable($path)) {
            throw new InvalidImageException("File is not readable: {$path}");
        }

        $label = $file instanceof UploadedFile
            ? ($file->getClientOriginalName() ?: $path)
            : $path;

        return Dimensions::fromArray($this->analyzeFile($path, $label));
    }

    /**
     * @see fromLocal()
     */
    public function tryFromLocal(string $path): ?Dimensions
    {
        return $this->attempt(fn () => $this->fromLocal($path));
    }

    /**
     * @see fromUrl()
     */
    public function tryFromUrl(string $url): ?Dimensions
    {
        return $this->attempt(fn () => $this->fromUrl($url));
    }

    /**
     * @see fromStorage()
     */
    public function tryFromStorage(string $diskName, string $path): ?Dimensions
    {
        return $this->attempt(fn () => $this->fromStorage($diskName, $path));
    }

    /**
     * @see fromContents()
     */
    public function tryFromContents(string $contents): ?Dimensions
    {
        return $this->attempt(fn () => $this->fromContents($contents));
    }

    /**
     * @see fromStream()
     *
     * @param  resource  $stream
     */
    public function tryFromStream($stream): ?Dimensions
    {
        return $this->attempt(fn () => $this->fromStream($stream));
    }

    /**
     * @see fromUploadedFile()
     */
    public function tryFromUploadedFile(SplFileInfo $file): ?Dimensions
    {
        return $this->attempt(fn () => $this->fromUploadedFile($file));
    }

    /**
     * Run a resolver, converting any package exception into a null result.
     * Non-package throwables (e.g. TypeError) are NOT swallowed.
     *
     * @param  callable(): Dimensions  $resolver
     */
    protected function attempt(callable $resolver): ?Dimensions
    {
        try {
            return $resolver();
        } catch (ImageDimensionsException) {
            return null;
        }
    }

    /**
     * Guzzle options for an image fetch: a total deadline and a connect
     * timeout (both enforced by cURL), no transparent decompression (so the
     * download cap counts the bytes actually written), and redirect hops
     * re-validated against the SSRF guard.
     *
     * @return array<string, mixed>
     */
    protected function requestOptions(): array
    {
        return [
            ...$this->httpOptions,
            'decode_content' => false,
            'allow_redirects' => [
                'max' => $this->urlGuard->maxRedirects(),
                'strict' => true,
                'referer' => false,
                'protocols' => ['http', 'https'],
                'on_redirect' => $this->urlGuard->redirectGuard(),
            ],
        ];
    }

    /**
     * Get dimensions from a URL, downloading only as much of the body as
     * needed.
     *
     * The body goes straight to a temporary file. Once remote_read_bytes have
     * arrived, the header is inspected and the transfer is cut short if it
     * already gives the dimensions; otherwise it continues up to the download
     * cap. Redirect and error bodies are never inspected.
     *
     * @return array{width: int, height: int}
     *
     * @throws UrlAccessException
     * @throws TemporaryFileException
     * @throws FileTooLargeException
     * @throws InvalidImageException
     */
    protected function getDimensionsFromUrl(string $url): array
    {
        $temp = new TemporaryFile($this->tempDir, 'imgdim_url_');
        $transfer = ['status' => 0, 'inspected' => false, 'svg' => false];

        $options = [
            ...$this->requestOptions(),
            // A path, not a handle: each hop of a redirect chain reopens (and
            // so empties) the file, and a handle would be closed along with
            // the discarded redirect response.
            'sink' => $temp->path(),
            'on_headers' => function (ResponseInterface $response) use (&$transfer, $temp): void {
                // A hop with an empty body never reopens the file, which then
                // still holds the previous hop's body.
                $temp->truncate();
                $transfer = ['status' => $response->getStatusCode(), 'inspected' => false, 'svg' => false];
            },
            'progress' => function (int $expected, int $received) use (&$transfer, $temp): void {
                $this->inspectTransfer($transfer, $temp, $expected, $received);
            },
        ];

        try {
            return $this->fetchIntoTemporaryFile($url, $temp, $options);
        } finally {
            $temp->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{width: int, height: int}
     *
     * @throws UrlAccessException
     * @throws TemporaryFileException
     * @throws FileTooLargeException
     * @throws InvalidImageException
     */
    private function fetchIntoTemporaryFile(string $url, TemporaryFile $temp, array $options): array
    {
        try {
            $response = Http::withHeaders(['Accept-Encoding' => 'identity'])->withOptions($options)->get($url);
        } catch (TransferStopped $stopped) {
            return $stopped->dimensions;
        } catch (ImageDimensionsException $e) {
            // A size cap was hit mid-transfer, or a redirect hop pointed at a
            // disallowed host.
            throw $e;
        } catch (Throwable $e) {
            if ($e instanceof StrayRequestException) {
                // A test that forgot to fake this URL; not a package failure.
                throw $e;
            }

            // Connection failures, timeouts, redirect loops, DNS errors, etc.
            throw UrlAccessException::couldNotOpen($url, $e);
        }

        if ($response->failed()) {
            throw UrlAccessException::couldNotOpen($url, null, $response->status());
        }

        $body = $response->toPsrResponse()->getBody();

        if ($body->getMetadata('uri') !== $temp->path()) {
            // Http::fake() (or a custom handler) hands back its own body and
            // may not have written it to the sink: a reused fake response is
            // already read to the end. Take the body itself instead.
            $temp->truncate();
            $this->copyBody($body, $temp);
        }

        clearstatcache(true, $temp->path());
        $size = @filesize($temp->path());
        $limit = $this->maxDownloadBytes;
        if ($limit > 0 && $size !== false && $size > $limit) {
            throw FileTooLargeException::forDownload($limit);
        }

        return $this->analyzeFile($temp->path(), $url);
    }

    /**
     * Progress callback of a URL transfer: enforce the size caps and stop the
     * transfer as soon as the header gives the dimensions.
     *
     * @param  array{status: int, inspected: bool, svg: bool}  $transfer
     *
     * @throws FileTooLargeException
     * @throws TransferStopped
     */
    private function inspectTransfer(array &$transfer, TemporaryFile $temp, int $expected, int $received): void
    {
        if ($transfer['status'] < 200 || $transfer['status'] >= 300) {
            return;
        }

        if (! $transfer['inspected'] && $received >= $this->remoteReadBytes) {
            $transfer['inspected'] = true;
            $this->inspectHeader($transfer, $temp, $expected);
        }

        $this->assertWithinTransferLimit($transfer['svg'], $received);
    }

    /**
     * @param  array{status: int, inspected: bool, svg: bool}  $transfer
     *
     * @throws FileTooLargeException
     * @throws TransferStopped
     */
    private function inspectHeader(array &$transfer, TemporaryFile $temp, int $expected): void
    {
        $head = (string) @file_get_contents($temp->path(), false, null, 0, $this->remoteReadBytes);

        if (SvgDimensionsExtractor::startsWithMarkup($head)) {
            // SVG needs the whole document; from here on its own cap applies.
            $transfer['svg'] = true;
        } elseif (($dimensions = $this->rasterDimensions(@getimagesizefromstring($head))) !== null) {
            throw new TransferStopped($dimensions);
        }

        // The header was not enough. If the declared length is already over
        // the cap, fail now instead of downloading up to it.
        $this->assertWithinTransferLimit($transfer['svg'], $expected);
    }

    /**
     * @throws FileTooLargeException
     */
    private function assertWithinTransferLimit(bool $svg, int $bytes): void
    {
        if ($svg && $this->svgMaxFileSize > 0 && $bytes > $this->svgMaxFileSize) {
            throw FileTooLargeException::forSvg($this->svgMaxFileSize);
        }

        if ($this->maxDownloadBytes > 0 && $bytes > $this->maxDownloadBytes) {
            throw FileTooLargeException::forDownload($this->maxDownloadBytes);
        }
    }

    /**
     * Copy a response body into a temporary file, up to the size caps.
     *
     * @throws FileTooLargeException
     * @throws TemporaryFileException
     */
    private function copyBody(StreamInterface $body, TemporaryFile $temp): void
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $svg = null;

        while (! $body->eof()) {
            $chunk = $body->read(1048576);
            if ($chunk === '') {
                break;
            }

            $svg ??= SvgDimensionsExtractor::startsWithMarkup($chunk);
            $temp->append($chunk);
            $this->assertWithinTransferLimit($svg, $temp->bytesWritten());
        }
    }

    /**
     * Get dimensions from a storage disk stream, reading only as much as needed.
     *
     * @param  Filesystem  $disk
     * @return array{width: int, height: int}
     *
     * @throws StorageAccessException
     * @throws TemporaryFileException
     * @throws FileTooLargeException
     * @throws InvalidImageException
     */
    protected function getDimensionsFromStorage($disk, string $path): array
    {
        try {
            $stream = $disk->readStream($path);
        } catch (Throwable $e) {
            // Keep the driver's real cause (auth failure, timeout, missing
            // bucket) reachable via getPrevious() for logging.
            throw StorageAccessException::couldNotReadStream($path, $e);
        }

        if (! is_resource($stream)) {
            throw StorageAccessException::couldNotReadStream($path);
        }

        $temp = new TemporaryFile($this->tempDir, 'imgdim_storage_');

        try {
            return $this->resolveFromStream($stream, $temp, $path);
        } finally {
            @fclose($stream);
        }
    }

    /**
     * Resolve dimensions from an open stream.
     *
     * Reads a header-sized chunk first; if that is not enough to determine the
     * dimensions, keeps reading the SAME stream (no second request, no full
     * in-memory buffering) up to the configured download limit.
     *
     * @param  resource  $stream
     * @return array{width: int, height: int}
     *
     * @throws FileTooLargeException
     * @throws TemporaryFileException
     * @throws InvalidImageException
     */
    protected function resolveFromStream($stream, TemporaryFile $temp, string $label): array
    {
        $temp->appendFromStream($stream, $this->remoteReadBytes);

        try {
            return $this->analyzeFile($temp->path(), $label);
        } catch (FileTooLargeException $e) {
            // A size cap was already exceeded (e.g. svg.max_file_size). Reading
            // further would only waste bandwidth — this is final, not a
            // "header was too short" failure.
            throw $e;
        } catch (InvalidImageException $e) {
            // The header alone was insufficient. If more data is available,
            // continue filling the same temp file and retry once.
            if (feof($stream)) {
                throw $e;
            }

            $limit = $this->maxDownloadBytes;

            if ($limit > 0) {
                // Read up to the cap, plus one byte to detect an overflow.
                $remaining = $limit + 1 - $temp->bytesWritten();
                if ($remaining > 0) {
                    $temp->appendFromStream($stream, $remaining);
                }
                if ($temp->bytesWritten() > $limit) {
                    throw FileTooLargeException::forDownload($limit);
                }
            } else {
                // No cap: drain the stream in large chunks until it is
                // exhausted (appendFromStream returns 0 once there is no more).
                while ($temp->appendFromStream($stream, 1048576) > 0) {
                    // keep reading
                }
            }

            return $this->analyzeFile($temp->path(), $label);
        }
    }

    /**
     * Determine image dimensions for a file already on the local filesystem.
     *
     * The format is taken from the contents, never from a file name or MIME
     * type: those are client-controlled for uploads and absent for streamed
     * temp files.
     *
     * @param  string  $path  Filesystem path to read.
     * @param  string  $label  Human-readable source description for error
     *                         messages. Never the path of an internal temp file,
     *                         which would leak the server's directory layout.
     * @return array{width: int, height: int}
     *
     * @throws InvalidImageException
     * @throws FileTooLargeException
     */
    protected function analyzeFile(string $path, string $label): array
    {
        $fileSize = @filesize($path);
        if ($fileSize === false || $fileSize === 0) {
            throw InvalidImageException::forPath($label, 'File is empty.');
        }

        if ($this->startsWithMarkup($path)) {
            if ($this->svgMaxFileSize > 0 && $fileSize > $this->svgMaxFileSize) {
                throw FileTooLargeException::forSvg($this->svgMaxFileSize);
            }

            $content = @file_get_contents($path);
            if ($content === false) {
                throw InvalidImageException::forPath($label, 'Could not read SVG file.');
            }

            return $this->svgExtractor->extract($content)->toArray();
        }

        return $this->rasterDimensions(@getimagesize($path))
            ?? throw InvalidImageException::forPath($label, 'Could not determine image dimensions');
    }

    /**
     * Dimensions from a getimagesize() result, or null if it has none.
     *
     * @param  array<int|string, mixed>|false  $size
     * @return array{width: int, height: int}|null
     */
    private function rasterDimensions(array|false $size): ?array
    {
        if ($size === false || ! is_int($size[0] ?? null) || ! is_int($size[1] ?? null) || $size[0] <= 0 || $size[1] <= 0) {
            return null;
        }

        return ['width' => $size[0], 'height' => $size[1]];
    }

    /**
     * Whether a file starts with markup, i.e. is SVG (or some other XML or
     * HTML document the SVG parser will reject).
     */
    private function startsWithMarkup(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $head = @fread($handle, 1024);
        @fclose($handle);

        return is_string($head) && SvgDimensionsExtractor::startsWithMarkup($head);
    }

    /**
     * Get cache key.
     *
     * The `v2` segment invalidates entries written by v1, which could hold
     * incorrect dimensions from the old viewBox miscalculation.
     */
    protected function getCacheKey(string $type, string $identifier, ?int $modifiedTime = null): string
    {
        $key = "image_dimensions:v2:{$type}:".md5($identifier);
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
     * @param  Closure(): array{width: int, height: int}  $callback
     */
    protected function getCachedOrCompute(string $key, Closure $callback): Dimensions
    {
        // Caching off, or a non-positive finite TTL ("do not cache").
        if (! $this->enableCache || ($this->cacheTtl !== null && $this->cacheTtl <= 0)) {
            return Dimensions::fromArray($callback());
        }

        // A null TTL means "cache indefinitely".
        if ($this->cacheTtl === null) {
            return Dimensions::fromArray(Cache::rememberForever($key, $callback));
        }

        return Dimensions::fromArray(Cache::remember($key, $this->cacheTtl, $callback));
    }
}
