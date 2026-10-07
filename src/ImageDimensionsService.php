<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\RequestException;
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
use Jackardios\ImageDimensions\Support\StreamReader;
use Jackardios\ImageDimensions\Support\SvgDimensionsExtractor;
use Jackardios\ImageDimensions\Support\TemporaryFile;
use Jackardios\ImageDimensions\Support\TransferStopped;
use Jackardios\ImageDimensions\Support\UrlGuard;
use Jackardios\ImageDimensions\Support\UrlNormalizer;
use Jackardios\ImageDimensions\Support\UrlRedactor;
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

    /**
     * getimagesize() types that are never reported, see rasterDimensions().
     */
    private const UNMEASURED_TYPES = [IMAGETYPE_WBMP, IMAGETYPE_XBM, IMAGETYPE_SWF, IMAGETYPE_SWC];

    /** The largest width or height reported: a signed 32-bit integer. */
    private const MAX_DIMENSION = 2147483647;

    /**
     * What php_getimagetype() takes the first bytes for GIF, JPEG, PNG, PSD,
     * BMP, JPEG 2000 (codestream and JP2), RIFF, TIFF (both byte orders), IFF
     * and ICO by, the same from PHP 8.1 to 8.5. Bytes that start with one of
     * these are that format to PHP, or nothing: a damaged PNG signature or
     * RIFF that is not WebP is not tried as anything else.
     */
    private const SIGNATURES = [
        'GIF',
        "\xFF\xD8\xFF",
        "\x89PN",
        '8BP',
        'BM',
        "\xFF\x4F\xFF",
        'RIF',
        "II\x2A\x00",
        "MM\x00\x2A",
        'FORM',
        "\x00\x00\x01\x00",
        "\x00\x00\x00\x0CjP  \r\n\x87\n",
    ];

    /**
     * @var int<8192, 1048576>
     *
     * @internal
     */
    protected int $remoteReadBytes;

    /** @internal */
    protected int $maxDownloadBytes;

    /** @internal */
    protected string $tempDir;

    /** @internal */
    protected bool $enableCache;

    /** @internal */
    protected ?int $cacheTtl;

    /** @internal */
    protected int $svgMaxFileSize;

    /**
     * @var array{timeout: float|int, connect_timeout: float|int, verify: bool}
     *
     * @internal
     */
    protected array $httpOptions;

    /** @internal */
    protected SvgDimensionsExtractor $svgExtractor;

    /** @internal */
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
            throw new InvalidImageException('Invalid URL provided: '.UrlRedactor::redact(trim($url)));
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
            throw new InvalidImageException("Invalid storage path: {$path}", previous: $e);
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

        return Dimensions::fromArray($this->analyzeString($contents, '(contents)'));
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
            return Dimensions::fromArray($this->resolveFromStream($stream, '(stream)'));
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
        $path = $file->getRealPath() ?: $file->getPathname();

        // An upload is named by its client name: its temporary path on the
        // server means nothing to the user and should not be shown to them.
        $label = $file instanceof UploadedFile
            ? ($file->getClientOriginalName() ?: $path)
            : $path;

        if (! is_file($path)) {
            throw FileNotFoundException::forLocal($label);
        }

        if (! is_readable($path)) {
            throw new InvalidImageException("File is not readable: {$label}");
        }

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
     *
     * @internal
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
     *
     * @internal
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
     * cap. An error response is abandoned once its headers arrive, and a
     * redirect body is never inspected, though it counts towards the cap.
     *
     * @return array{width: int, height: int}
     *
     * @throws UrlAccessException
     * @throws TemporaryFileException
     * @throws FileTooLargeException
     * @throws InvalidImageException
     *
     * @internal
     */
    protected function getDimensionsFromUrl(string $url): array
    {
        $temp = new TemporaryFile($this->tempDir, 'imgdim_url_');
        $transfer = ['status' => 0, 'inspected' => false, 'svg' => false, 'earlier' => 0];

        $options = [
            ...$this->requestOptions(),
            // A path, not a handle: each hop of a redirect chain reopens (and
            // so empties) the file, and a handle would be closed along with
            // the discarded redirect response.
            'sink' => $temp->path(),
            'on_headers' => function (ResponseInterface $response) use (&$transfer, $temp, $url): void {
                if ($response->getStatusCode() >= 400) {
                    throw UrlAccessException::couldNotOpen($url, null, $response->getStatusCode());
                }

                // The file still holds the previous hop's body: the download
                // cap is for the whole redirect chain.
                clearstatcache(true, $temp->path());
                $earlier = $transfer['earlier'] + (int) @filesize($temp->path());

                // A hop with an empty body never reopens the file, which would
                // then still hold that body.
                $temp->truncate();
                $transfer = ['status' => $response->getStatusCode(), 'inspected' => false, 'svg' => false, 'earlier' => $earlier];
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
        } catch (Throwable $e) {
            $own = self::ownException($e);

            if ($own instanceof TransferStopped) {
                return $own->dimensions;
            }

            if ($own !== null) {
                // A size cap was hit mid-transfer, or a redirect hop pointed
                // at a disallowed host.
                throw $own;
            }

            if ($e instanceof RequestException) {
                // An error status: Laravel reports it in place of the
                // exception that abandoned the transfer.
                throw UrlAccessException::couldNotOpen($url, $e, $e->response->status());
            }

            if ($e instanceof StrayRequestException) {
                // A test that forgot to fake this URL; not a package failure.
                throw $e;
            }

            // Connection failures, timeouts, redirect loops, DNS errors, etc.
            throw UrlAccessException::couldNotOpen($url, $e);
        }

        // A redirect that was not followed (no Location, say) is no image either.
        if (! $response->successful()) {
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

        return $this->analyzeFile($temp->path(), UrlRedactor::redact($url));
    }

    /**
     * What this package threw from a transfer callback. Guzzle 7 lets it
     * through; Guzzle 8 wraps it (a RequestException for `progress`, a
     * ResponseException for `on_headers`), and Laravel may wrap that again.
     */
    private static function ownException(Throwable $e): TransferStopped|ImageDimensionsException|null
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof TransferStopped || $cause instanceof ImageDimensionsException) {
                return $cause;
            }
        }

        return null;
    }

    /**
     * Progress callback of a URL transfer: enforce the size caps and stop the
     * transfer as soon as the header gives the dimensions.
     *
     * @param  array{status: int, inspected: bool, svg: bool, earlier: int}  $transfer
     *
     * @throws FileTooLargeException
     * @throws TransferStopped
     */
    private function inspectTransfer(array &$transfer, TemporaryFile $temp, int $expected, int $received): void
    {
        if ($transfer['status'] < 300 && ! $transfer['inspected'] && $received >= $this->remoteReadBytes) {
            $transfer['inspected'] = true;
            $this->inspectHeader($transfer, $temp, $expected);
        }

        $this->assertWithinCaps($transfer['svg'], $received, $transfer['earlier']);
    }

    /**
     * @param  array{status: int, inspected: bool, svg: bool, earlier: int}  $transfer
     *
     * @throws FileTooLargeException
     * @throws TransferStopped
     */
    private function inspectHeader(array &$transfer, TemporaryFile $temp, int $expected): void
    {
        $head = (string) @file_get_contents($temp->path(), false, null, 0, $this->remoteReadBytes);

        if (self::isMarkup($head)) {
            // SVG needs the whole document; from here on its own cap applies.
            $transfer['svg'] = true;
        } elseif (($dimensions = $this->rasterDimensions(
            self::hasRasterSignature($head) ? @getimagesizefromstring($head) : false,
            static fn () => HeifDimensionsReader::fromString($head),
            complete: false,
        )) !== null) {
            throw new TransferStopped($dimensions);
        }

        // The header was not enough. If the declared length is already over
        // the cap, fail now instead of downloading up to it.
        $this->assertWithinCaps($transfer['svg'], $expected, $transfer['earlier']);
    }

    /**
     * The SVG cap first: when both are exceeded, it is the stricter one.
     *
     * @param  int  $bytes  The size of the body.
     * @param  int  $earlier  Bytes downloaded before it, for earlier redirects.
     *
     * @throws FileTooLargeException
     */
    private function assertWithinCaps(bool $svg, int $bytes, int $earlier = 0): void
    {
        if ($svg && $this->svgMaxFileSize > 0 && $bytes > $this->svgMaxFileSize) {
            throw FileTooLargeException::forSvg($this->svgMaxFileSize);
        }

        if ($this->maxDownloadBytes > 0 && $earlier + $bytes > $this->maxDownloadBytes) {
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

            $svg ??= self::isMarkup($chunk);
            $temp->append($chunk);
            $this->assertWithinCaps($svg, $temp->bytesWritten());
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
     *
     * @internal
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

        try {
            return $this->resolveFromStream($stream, $path);
        } finally {
            @fclose($stream);
        }
    }

    /**
     * Resolve dimensions from an open stream, reading only as much as needed.
     *
     * The header (remote_read_bytes) is analyzed in memory, which settles most
     * raster images. An SVG document is read whole, but never past its own
     * size cap. A raster image whose header was not enough is read on into a
     * temporary file, up to the download cap.
     *
     * @param  resource  $stream
     * @return array{width: int, height: int}
     *
     * @throws FileTooLargeException
     * @throws TemporaryFileException
     * @throws InvalidImageException
     *
     * @internal
     */
    protected function resolveFromStream($stream, string $label): array
    {
        $head = StreamReader::read($stream, $this->remoteReadBytes);
        if ($head === '') {
            throw InvalidImageException::forPath($label, 'File is empty.');
        }

        if (self::isMarkup($head)) {
            // One byte past the stricter cap shows that it is exceeded.
            $limit = $this->svgReadLimit();
            $content = $head.StreamReader::read($stream, $limit === null ? PHP_INT_MAX : $limit + 1 - strlen($head));

            $this->assertWithinCaps(true, strlen($content));

            return $this->svgExtractor->extract($content)->toArray();
        }

        $dimensions = $this->rasterDimensions(
            self::hasRasterSignature($head) ? @getimagesizefromstring($head) : false,
            static fn () => HeifDimensionsReader::fromString($head),
            complete: feof($stream),
        );

        if ($dimensions !== null) {
            return $dimensions;
        }

        if (feof($stream)) {
            throw InvalidImageException::forPath($label, 'Could not determine image dimensions');
        }

        // The header was not enough (large metadata before the image data,
        // say): read on, into a file rather than memory. The file is deleted
        // once $temp goes out of scope, however this method ends.
        $temp = new TemporaryFile($this->tempDir, 'imgdim_stream_');
        $temp->append($head);
        $limit = $this->maxDownloadBytes;

        // One byte past the cap shows that it is exceeded.
        $temp->appendFromStream($stream, $limit === 0 ? PHP_INT_MAX : $limit + 1 - strlen($head));
        $this->assertWithinCaps(false, $temp->bytesWritten());

        return $this->analyzeFile($temp->path(), $label);
    }

    /**
     * How much of an SVG document a stream may deliver: the smaller of its
     * own cap and the download cap, or null if neither applies.
     */
    private function svgReadLimit(): ?int
    {
        $caps = array_filter([$this->svgMaxFileSize, $this->maxDownloadBytes], static fn (int $cap) => $cap > 0);

        return $caps === [] ? null : min($caps);
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
            throw new InvalidImageException("Invalid storage path: {$path}", previous: $e);
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
     *
     * @internal
     */
    protected function analyzeFile(string $path, string $label): array
    {
        $fileSize = @filesize($path);
        if ($fileSize === false || $fileSize === 0) {
            throw InvalidImageException::forPath($label, 'File is empty.');
        }

        // Judged by the first kilobyte, as isMarkup() judges a header.
        $head = (string) @file_get_contents($path, false, null, 0, 1024);

        if (SvgDimensionsExtractor::startsWithMarkup($head)) {
            // Checked before the document is read.
            if ($this->svgMaxFileSize > 0 && $fileSize > $this->svgMaxFileSize) {
                throw FileTooLargeException::forSvg($this->svgMaxFileSize);
            }

            $content = @file_get_contents($path);
            if ($content === false) {
                throw InvalidImageException::forPath($label, 'Could not read SVG file.');
            }

            return $this->analyzeSvg($content, $label);
        }

        return $this->rasterDimensions(self::hasRasterSignature($head) ? @getimagesize($path) : false, static fn () => HeifDimensionsReader::fromFile($path))
            ?? throw InvalidImageException::forPath($label, 'Could not determine image dimensions');
    }

    /**
     * Image dimensions of contents held in memory, as analyzeFile() finds
     * them for a file.
     *
     * @return array{width: int, height: int}
     *
     * @throws InvalidImageException
     * @throws FileTooLargeException
     */
    private function analyzeString(string $contents, string $label): array
    {
        if (self::isMarkup($contents)) {
            return $this->analyzeSvg($contents, $label);
        }

        return $this->rasterDimensions(self::hasRasterSignature($contents) ? @getimagesizefromstring($contents) : false, static fn () => HeifDimensionsReader::fromString($contents))
            ?? throw InvalidImageException::forPath($label, 'Could not determine image dimensions');
    }

    /**
     * @return array{width: int, height: int}
     *
     * @throws InvalidImageException
     * @throws FileTooLargeException
     */
    private function analyzeSvg(string $content, string $label): array
    {
        if ($this->svgMaxFileSize > 0 && strlen($content) > $this->svgMaxFileSize) {
            throw FileTooLargeException::forSvg($this->svgMaxFileSize);
        }

        return $this->svgExtractor->extract($content)->toArray();
    }

    /**
     * Whether bytes read from the start of a source are markup, judged, as
     * for a file, by the first kilobyte.
     */
    private static function isMarkup(string $head): bool
    {
        return SvgDimensionsExtractor::startsWithMarkup(substr($head, 0, 1024));
    }

    /**
     * Whether the bytes start with a signature that getimagesize() itself
     * takes for an image format it reads from the header. Nothing else may
     * reach getimagesize(): what it does not recognise by a signature it
     * tries as WBMP and then as XBM.
     *
     * - Compressed Flash (`CWS`) is inflated whole to find a size, so 100 KB
     *   of it allocate 100 MB and exhaust the memory limit, which is a
     *   fatal error no caller can catch; Flash (`FWS`) is not an image.
     * - XBM is a text format read line by line: a large file without a line
     *   break is read into memory whole, and any text with two `#define`
     *   lines is an "image" of the size they name.
     *
     * @param  string  $head  The first bytes of the source, 144 or more unless it is shorter.
     */
    private static function hasRasterSignature(string $head): bool
    {
        foreach (self::SIGNATURES as $signature) {
            if (str_starts_with($head, $signature)) {
                return true;
            }
        }

        // PHP takes `CWS` and `FWS` for Flash before it looks for an `ftyp` box.
        return ! str_starts_with($head, 'CWS') && ! str_starts_with($head, 'FWS') && self::hasMeasurableFtyp($head);
    }

    /**
     * Whether the bytes start with an `ftyp` box that getimagesize() takes
     * for AVIF or, since PHP 8.5, for HEIF. Any other ISO media file (MP4,
     * a made-up brand) is not recognised there and is tried as XBM.
     *
     * This repeats PHP's own test: a box of 16 bytes or more with `avif` or
     * `avis` as the major brand or as one of the first 32 compatible brands
     * inside the box. PHP 8.5 also takes a major brand of `mif1`, `heic` or
     * `heix` for HEIF, whatever the size of the box.
     */
    private static function hasMeasurableFtyp(string $head): bool
    {
        if (substr($head, 4, 4) !== 'ftyp') {
            return false;
        }

        $heif = defined('IMAGETYPE_HEIF') && in_array(substr($head, 8, 4), ['mif1', 'heic', 'heix'], true);

        /** @var array{1: int} $box */
        $box = unpack('N', $head);
        if ($heif || $box[1] < 16) {
            return $heif;
        }

        // The major brand at 8, the minor version at 12, then the brands.
        $end = min($box[1], 144);
        for ($offset = 8; $offset + 4 <= $end; $offset += 4) {
            if ($offset !== 12 && in_array(substr($head, $offset, 4), ['avif', 'avis'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dimensions of a raster image from its getimagesize() result, or null
     * if it has none.
     *
     * WBMP and XBM are rejected: they have no signature, so getimagesize()
     * takes almost any bytes starting with two NULs for the first and any
     * text with two `#define` lines for the second. Flash is not an image.
     * hasRasterSignature() already keeps all four from getimagesize(); the
     * type is checked as well, so that a gap there cannot yield a size.
     * HEIF is read from its own metadata: getimagesize() cannot read it
     * before PHP 8.5 and ignores the clean aperture since.
     *
     * @param  array<int|string, mixed>|false  $size
     * @param  Closure(): (array{width: int, height: int}|null)  $readHeif
     * @param  bool  $complete  Whether these are all the bytes, not a header.
     * @return array{width: int, height: int}|null
     */
    private function rasterDimensions(array|false $size, Closure $readHeif, bool $complete = true): ?array
    {
        $type = $size === false ? null : ($size[2] ?? null);

        if (
            ! in_array($type, self::UNMEASURED_TYPES, true)
            && $type !== self::IMAGETYPE_HEIF
            && ($dimensions = $this->sizeDimensions($size)) !== null
        ) {
            return $dimensions;
        }

        // Metadata the reader rejects (a truncated `meta` box, say) may still
        // give PHP 8.5+ a size, though one that ignores any crop. In a header,
        // the rest of the metadata may yet hold the crop.
        return $readHeif()
            ?? ($complete && $type === self::IMAGETYPE_HEIF ? $this->sizeDimensions($size) : null);
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

        // As for SVG and HEIF. A header can name any 32-bit size, such as a
        // PNG of 4294967295x4294967295 or a BMP with a negative width, which
        // getimagesize() reports as 4294967263.
        if ($size[0] > self::MAX_DIMENSION || $size[1] > self::MAX_DIMENSION) {
            return null;
        }

        return ['width' => $size[0], 'height' => $size[1]];
    }

    /**
     * Get cache key.
     *
     * The `v2` segment invalidates entries written by v1, which could hold
     * incorrect dimensions from the old viewBox miscalculation.
     *
     * @param  string|list<mixed>  $identity  What identifies the source's
     *                                        current contents (URL, or path plus file metadata).
     *
     * @internal
     */
    protected function getCacheKey(string $type, string|array $identity): string
    {
        // Serialized, the parts stay apart ("a:b" + "c" and "a" + "b:c"
        // differ), whatever bytes they hold.
        return "image_dimensions:v2:{$type}:".md5(serialize($identity));
    }

    /**
     * Get cached value or compute and cache.
     *
     * The callback returns a primitive `array{width, height}` so cache stores
     * hold plain data, not a serialized object; the result is hydrated into a
     * {@see Dimensions} value object before returning.
     *
     * @param  Closure(): array{width: int, height: int}  $callback
     *
     * @internal
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
