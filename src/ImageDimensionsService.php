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
use Jackardios\ImageDimensions\Support\HeifDimensionsReader;
use Jackardios\ImageDimensions\Support\SvgDimensionsExtractor;
use Jackardios\ImageDimensions\Support\TemporaryFile;
use Jackardios\ImageDimensions\Support\TransferStopped;
use Jackardios\ImageDimensions\Support\UrlGuard;
use Jackardios\ImageDimensions\Support\UrlNormalizer;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathTraversalDetected;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SplFileInfo;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

class ImageDimensionsService implements ImageDimensionsContract
{
    /** IMAGETYPE_HEIF, defined since PHP 8.5. */
    private const IMAGETYPE_HEIF = 20;

    /** @var int<8192, 1048576> */
    protected int $remoteReadBytes;

    protected int $maxDownloadBytes;

    protected string $tempDir;

    protected bool $enableCache;

    protected ?int $cacheTtl;

    protected int $svgMaxFileSize;

    /** @var array{timeout: float|int, connect_timeout: float|int, verify: bool} */
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

        // Values usually come from environment variables, as strings: an
        // empty or unrecognized one gives the default rather than 0 or true
        // (an empty IMAGE_DIMENSIONS_MAX_DOWNLOAD_BYTES used to lift the cap).
        $http = is_array($config['http'] ?? null) ? $config['http'] : [];
        $svg = is_array($config['svg'] ?? null) ? $config['svg'] : [];
        $url = is_array($config['url'] ?? null) ? $config['url'] : [];

        $this->remoteReadBytes = max(8192, min(1048576, self::intSetting($config['remote_read_bytes'] ?? null, 131072))); // 8KB-1MB
        $maxDownloadBytes = self::intSetting($config['max_download_bytes'] ?? null, 33554432);
        // 0 means unlimited; otherwise never below the initial read size.
        $this->maxDownloadBytes = $maxDownloadBytes <= 0 ? 0 : max($maxDownloadBytes, $this->remoteReadBytes);
        // Resolved here, not in the config file, where `config:cache` would
        // fix the temp directory of the machine that built the cache.
        $tempDir = $config['temp_dir'] ?? null;
        $this->tempDir = is_string($tempDir) && $tempDir !== '' ? $tempDir : sys_get_temp_dir();
        $this->enableCache = self::boolSetting($config['enable_cache'] ?? null, true);
        // null => cache forever; <= 0 => do not cache; otherwise, seconds.
        $this->cacheTtl = array_key_exists('cache_ttl', $config) && $config['cache_ttl'] === null
            ? null
            : self::intSetting($config['cache_ttl'] ?? null, 3600);
        $this->svgMaxFileSize = max(0, self::intSetting($svg['max_file_size'] ?? null, 10485760));
        $this->httpOptions = [
            'timeout' => self::secondsSetting($http['timeout'] ?? null, 60),
            'connect_timeout' => self::secondsSetting($http['connect_timeout'] ?? null, 10),
            'verify' => self::boolSetting($http['verify_ssl'] ?? null, true),
        ];
        $this->svgExtractor = new SvgDimensionsExtractor;

        $allowedHosts = $url['allowed_hosts'] ?? [];
        $this->urlGuard = new UrlGuard(
            self::boolSetting($url['allow_private_hosts'] ?? null, false),
            // A comma-separated string, as the environment variable holds.
            is_string($allowedHosts) ? explode(',', $allowedHosts) : (is_array($allowedHosts) ? array_values($allowedHosts) : []),
            self::intSetting($url['max_redirects'] ?? null, 5),
        );
    }

    /**
     * An integer setting: an integer or a numeric string, else the default.
     */
    private static function intSetting(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return $default;
        }

        // Clamped far beyond any sensible value, where the cast is defined.
        return (int) max(-1e15, min(1e15, (float) $value));
    }

    /**
     * A duration in seconds, fractions allowed; 0 disables the timeout.
     */
    private static function secondsSetting(mixed $value, int $default): float|int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        return is_numeric($value) && is_finite((float) $value) ? max(0, (float) $value) : $default;
    }

    /**
     * A boolean setting: a boolean, or "true"/"false", "1"/"0", "yes"/"no",
     * "on"/"off". Anything else, including an empty string, gives the
     * default. A plain cast would turn "off" and "no" into true.
     */
    private static function boolSetting(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
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

        $this->assertValidPath($path);

        // Resolve real path to handle symlinks and relative paths
        $realPath = realpath($path);
        if ($realPath === false || ! is_file($realPath)) {
            throw FileNotFoundException::forLocal($path);
        }

        if (! is_readable($realPath)) {
            throw new InvalidImageException("File is not readable: {$path}");
        }

        // Any rewrite changes one of these, even one within the same second
        // or one that restores the modification time (cp -p, an atomic
        // rename): the ctime or the inode then differs.
        $stat = @stat($realPath);
        if ($stat === false) {
            // Removed since the checks above.
            throw FileNotFoundException::forLocal($path);
        }

        $cacheKey = $this->getCacheKey('local', [
            $realPath, $stat['size'], $stat['mtime'], $stat['ctime'], $stat['ino'],
        ]);

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
        $scheme = parse_url(trim($url), PHP_URL_SCHEME);
        if (is_string($scheme) && ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new InvalidImageException('Only HTTP and HTTPS URLs are supported');
        }

        // Checked, cached and fetched in this one form.
        $normalized = UrlNormalizer::normalize($url);
        if ($normalized === null) {
            throw new InvalidImageException('Invalid URL provided: '.trim($url));
        }

        $url = $normalized;

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

        $this->assertValidPath($path);

        try {
            $disk = Storage::disk($diskName);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidImageException("Storage disk '{$diskName}' does not exist");
        }

        // Fast path: a local disk is just a filesystem path, so reuse fromLocal
        // (and its stat-based cache key). The existence check comes first: it
        // rejects a path that would leave the disk's root.
        if (method_exists($disk, 'getAdapter') && $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            if (! $this->storageFileExists($disk, $path)) {
                throw FileNotFoundException::forStorage($diskName, $path);
            }

            return $this->fromLocal($disk->path($path));
        }

        // The modification time invalidates the cache when the file changes,
        // and proves that the file exists: a cache hit then costs one remote
        // call instead of two. Some drivers cannot report it; the key then
        // omits it.
        try {
            $modifiedTime = $disk->lastModified($path);
        } catch (PathTraversalDetected $e) {
            throw new InvalidImageException("Invalid storage path: {$path}", 0, $e);
        } catch (Throwable) {
            if (! $this->storageFileExists($disk, $path)) {
                throw FileNotFoundException::forStorage($diskName, $path);
            }

            $modifiedTime = null;
        }

        $cacheKey = $this->getCacheKey('storage', [$diskName, $path, $modifiedTime]);

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
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidImageException('A readable stream resource is required.');
        }

        // The whole stream is measured, not what is left of it, and the
        // caller's position is kept. A stream that cannot seek (a pipe, a
        // socket) is read from where it is.
        $position = $this->rewindIfSeekable($stream);

        try {
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
        } finally {
            if ($position !== null) {
                @fseek($stream, $position);
            }
        }
    }

    /**
     * Move a seekable stream to its start.
     *
     * @param  resource  $stream
     * @return int|null The position it had, or null if it did not move.
     */
    private function rewindIfSeekable($stream): ?int
    {
        if (! stream_get_meta_data($stream)['seekable']) {
            return null;
        }

        $position = @ftell($stream);

        return $position !== false && @fseek($stream, 0) === 0 ? $position : null;
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
        } elseif (($dimensions = $this->rasterDimensions(
            @getimagesizefromstring($head),
            static fn () => HeifDimensionsReader::fromString($head),
        )) !== null) {
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
     * @param  Filesystem  $disk
     *
     * @throws InvalidImageException
     * @throws StorageAccessException
     */
    private function storageFileExists($disk, string $path): bool
    {
        try {
            return $disk->exists($path);
        } catch (PathTraversalDetected $e) {
            throw new InvalidImageException("Invalid storage path: {$path}", 0, $e);
        } catch (Throwable $e) {
            throw StorageAccessException::couldNotAccess($path, $e);
        }
    }

    /**
     * @throws InvalidImageException
     */
    private function assertValidPath(string $path): void
    {
        if ($path === '') {
            throw new InvalidImageException('Path must be a non-empty string');
        }

        // Filesystem functions throw a ValueError for these.
        if (str_contains($path, "\0")) {
            throw new InvalidImageException('Path must not contain NUL bytes');
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

        return $this->rasterDimensions(@getimagesize($path), static fn () => HeifDimensionsReader::fromFile($path))
            ?? throw InvalidImageException::forPath($label, 'Could not determine image dimensions');
    }

    /**
     * Dimensions of a raster image from its getimagesize() result, or null
     * if it has none.
     *
     * WBMP is rejected: it has no signature, so getimagesize() takes almost
     * any bytes starting with two NULs for one. HEIF is read from its own
     * metadata: getimagesize() cannot read it before PHP 8.5 and ignores the
     * clean aperture since.
     *
     * @param  array<int|string, mixed>|false  $size
     * @param  Closure(): (array{width: int, height: int}|null)  $readHeif
     * @return array{width: int, height: int}|null
     */
    private function rasterDimensions(array|false $size, Closure $readHeif): ?array
    {
        $type = $size === false ? null : ($size[2] ?? null);

        if ($type !== IMAGETYPE_WBMP && $type !== self::IMAGETYPE_HEIF && ($dimensions = $this->sizeDimensions($size)) !== null) {
            return $dimensions;
        }

        // Metadata the reader rejects (a truncated `meta` box, say) may still
        // give PHP 8.5+ a size, though one that ignores any crop.
        return $readHeif()
            ?? ($type === self::IMAGETYPE_HEIF ? $this->sizeDimensions($size) : null);
    }

    /**
     * @param  array<int|string, mixed>|false  $size
     * @return array{width: int, height: int}|null
     */
    private function sizeDimensions(array|false $size): ?array
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
     *
     * @param  string|list<mixed>  $identity  What identifies the source's
     *                                        current contents (URL, or path plus file metadata).
     */
    protected function getCacheKey(string $type, string|array $identity): string
    {
        // JSON keeps the parts apart: "a:b" + "c" and "a" + "b:c" differ.
        return "image_dimensions:v2:{$type}:".md5(is_string($identity) ? $identity : (string) json_encode($identity));
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

        // An entry that is not a pair of positive integers was not written
        // by this package (or got corrupted): treat it as a miss.
        $cached = Cache::get($key);
        if (is_array($cached)
            && is_int($cached['width'] ?? null) && $cached['width'] > 0
            && is_int($cached['height'] ?? null) && $cached['height'] > 0
        ) {
            return new Dimensions($cached['width'], $cached['height']);
        }

        $dimensions = $callback();

        // A null TTL means "cache indefinitely".
        if ($this->cacheTtl === null) {
            Cache::forever($key, $dimensions);
        } else {
            Cache::put($key, $dimensions, $this->cacheTtl);
        }

        return Dimensions::fromArray($dimensions);
    }
}
