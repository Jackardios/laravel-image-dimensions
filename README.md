# Laravel Image Dimensions

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jackardios/laravel-image-dimensions.svg?style=flat-square)](https://packagist.org/packages/jackardios/laravel-image-dimensions)
[![Tests](https://github.com/Jackardios/laravel-image-dimensions/actions/workflows/tests.yml/badge.svg)](https://github.com/Jackardios/laravel-image-dimensions/actions/workflows/tests.yml)

A robust and efficient Laravel package to get the dimensions (width and height) of images from local files, URLs, Laravel Storage disks, raw contents, streams, and uploaded files. It reads only as much of a remote file as it needs, caches results, and guards outgoing requests against SSRF.

## Features

- **Multiple sources** — local paths, remote URLs, Storage disks, raw binary contents, open streams, and uploaded files.
- **Wide format support** — PNG, JPEG, GIF, WebP, BMP, and other formats understood by `getimagesize()`, HEIF/HEIC on every PHP version, plus a dedicated SVG parser. See [Formats](#formats).
- **Correct SVG parsing** — reads `width`/`height` (with CSS absolute units) and falls back to `viewBox`, including namespaced roots (`<svg:svg>`) and documents with an internal DTD subset.
- **Bounded remote fetching** — a download stops as soon as its first bytes give the dimensions, runs under a total deadline, and never reads past a configurable download limit.
- **Built-in caching** — results are cached and automatically invalidated when a file changes.
- **SSRF protection** — `fromUrl()` blocks private, loopback, link-local, and reserved addresses by default, and re-validates every redirect hop.
- **Typed result** — returns an immutable `Dimensions` value object that still behaves like the old `['width' => ..., 'height' => ...]` array.

## Requirements

- PHP 8.2+ with the `curl`, `dom` and `libxml` extensions
- Laravel 12.x or 13.x
- Guzzle 7.15.2+ or 8.0.1+

`ext-intl` is optional: without it, internationalized host names are converted by `symfony/polyfill-intl-idn`.

> Upgrading from v1? See [UPGRADE.md](UPGRADE.md). v1.x supports Laravel 10 to 13 and PHP 8.1+.

## Installation

```bash
composer require jackardios/laravel-image-dimensions
```

The service provider and `ImageDimensions` facade are registered automatically.

To publish the configuration file:

```bash
php artisan vendor:publish --tag="image-dimensions-config"
```

This creates `config/image-dimensions.php`.

## Usage

Every `from*` method returns a [`Dimensions`](#the-dimensions-object) object on success or throws an [exception](#exception-handling) on failure. Every method has a `tryFrom*` counterpart that returns `null` instead of throwing.

### From a local file

```php
use Jackardios\ImageDimensions\Facades\ImageDimensions;

$dimensions = ImageDimensions::fromLocal(public_path('images/photo.png'));

$dimensions->width;   // 800
$dimensions->height;  // 600
$dimensions['width']; // 800 — array access still works
```

### From a remote URL

Only `http` and `https` URLs are supported. Hosts resolving to private or reserved networks are rejected by default (see [Security](#security)).

```php
$dimensions = ImageDimensions::fromUrl('https://example.com/image.jpg');
```

URLs are taken as a browser takes them: an internationalized host (`https://пример.рф/`), an underscore in the host, spaces or other non-ASCII characters in the path. Each URL is brought into one normal form before anything else: a lower-case ASCII host (punycode), percent-encoded path and query, no default port, no fragment. The SSRF guard checks, the cache keys and the HTTP client fetches that same URL, so different spellings of one URL share a cache entry.

The download stops as soon as the first `remote_read_bytes` give the dimensions. `http.timeout` is a deadline for the whole request, headers included. The body is requested uncompressed (`Accept-Encoding: identity`) and never decompressed, so the download limit counts the bytes that actually arrive.

### From a Laravel Storage disk

Works with local and cloud drivers (e.g. `s3`). Local disks are read directly; remote disks stream only the bytes needed.

```php
$dimensions = ImageDimensions::fromStorage('s3', 'images/banner.svg');
```

### From raw contents, a stream, or an uploaded file

```php
// Raw binary contents (e.g. already in memory)
$dimensions = ImageDimensions::fromContents($binaryString);

// Any readable stream resource
$stream = fopen('php://temp', 'r+b');
// ... write image bytes into $stream ...
$dimensions = ImageDimensions::fromStream($stream);

// An uploaded file (Illuminate\Http\UploadedFile, Symfony's UploadedFile,
// or any SplFileInfo)
$dimensions = ImageDimensions::fromUploadedFile($request->file('avatar'));
```

### Non-throwing variants

```php
$dimensions = ImageDimensions::tryFromUrl($url);

if ($dimensions === null) {
    // could not be determined — handle gracefully
}
```

A seekable stream is read from its start, whatever its position, and moved back to where it was afterwards, also when measuring fails. A stream that cannot seek (a socket, a pipe) is read from where it is. The stream is not closed. Only as much of it is read as the dimensions need: an image whose first `remote_read_bytes` give them is not read further.

`tryFrom*` returns `null` only for the package's own exceptions (`ImageDimensionsException` and its subclasses), that is, for a bad input or a failing source. Anything else still surfaces, notably a failing cache store: a broken Redis is not a missing image.

### Dependency injection

The service is bound to the concrete class, the contract, and the `image-dimensions` alias — inject whichever you prefer. The binding is scoped: every binding and the facade share one instance, rebuilt with the current configuration for each Octane request and each queued job.

```php
use Jackardios\ImageDimensions\Contracts\ImageDimensions;

public function __construct(private ImageDimensions $images) {}

// ...
$this->images->fromLocal($path);
```

## The `Dimensions` object

`Jackardios\ImageDimensions\Dimensions` is an immutable value object.

```php
$d = ImageDimensions::fromLocal($path);

$d->width;          // int
$d->height;         // int
$d->ratio();        // float (width / height)
$d->isLandscape();  // bool
$d->isPortrait();   // bool
$d->isSquare();     // bool
$d->toArray();      // ['width' => 800, 'height' => 600]
(string) $d;        // "800x600"

// Backwards-compatible access
$d['width'];              // 800  (ArrayAccess)
json_encode($d);          // {"width":800,"height":600}  (JsonSerializable)
```

Attempting to mutate it (`$d['width'] = 100`) throws a `LogicException`.

## Exception handling

All exceptions extend `Jackardios\ImageDimensions\Exceptions\ImageDimensionsException`, so you can catch that single type or handle each case individually.

| Exception | Extends | Thrown when |
| --- | --- | --- |
| `ImageDimensionsException` | `\Exception` | Base type for every exception below. |
| `FileNotFoundException` | `ImageDimensionsException` | The local or storage path does not exist. |
| `InvalidImageException` | `ImageDimensionsException` | The source is empty, not a supported image, or its dimensions cannot be determined. |
| `FileTooLargeException` | `InvalidImageException` | An SVG exceeds `svg.max_file_size`, or a download exceeds `max_download_bytes`. |
| `UrlAccessException` | `ImageDimensionsException` | The URL could not be fetched (connection error, timeout, non-2xx status). |
| `UrlNotAllowedException` | `UrlAccessException` | The URL is blocked by the SSRF guard (private/reserved host, disallowed scheme, or not in the allowlist). |
| `StorageAccessException` | `ImageDimensionsException` | The file stream could not be read from the storage disk. |
| `TemporaryFileException` | `ImageDimensionsException` | A temporary file could not be created or written (usually a permissions problem). |

Because `FileTooLargeException` and `UrlNotAllowedException` extend existing types, code that already catches `InvalidImageException` or `UrlAccessException` keeps working.

Messages quote a URL without its secrets: user info becomes `***@`, query values become `***` (their names are kept), and the fragment is left out. The previous exception, attached for debugging, comes from Guzzle or cURL and may still quote the full URL, so take care when logging the whole chain. An upload is named by its client name, never by its temporary path on the server.

```php
use Jackardios\ImageDimensions\Exceptions\FileTooLargeException;
use Jackardios\ImageDimensions\Exceptions\UrlNotAllowedException;
use Jackardios\ImageDimensions\Exceptions\ImageDimensionsException;

try {
    $dimensions = ImageDimensions::fromUrl($url);
} catch (UrlNotAllowedException $e) {
    // blocked by the SSRF guard
} catch (FileTooLargeException $e) {
    // exceeded the configured size limit
} catch (ImageDimensionsException $e) {
    // anything else from this package
}
```

## Security

### SSRF protection

`fromUrl()` (and redirect handling) validate the target before connecting:

- Only `http` and `https` schemes are allowed.
- By default, hosts that resolve to private, loopback, link-local, or reserved ranges — including the cloud metadata endpoint `169.254.169.254`, CGNAT (`100.64.0.0/10`), and the IETF test networks — are rejected for both IPv4 and IPv6. This covers IPv4-mapped IPv6 (`::ffff:`) as well as addresses that tunnel an IPv4 destination inside IPv6: 6to4 (`2002::/16`) and NAT64 (`64:ff9b::/96`), which are judged by the IPv4 address they carry. The rest of the reserved `::/8` block is rejected outright. Set `url.allow_private_hosts` to `true` to restore the old, unrestricted behaviour (not recommended).
- Every redirect hop is re-validated against the same rules, up to `url.max_redirects`.
- Setting `url.allowed_hosts` switches to a strict allowlist: only the listed hostnames may be fetched.

The host is resolved when a URL is actually fetched, not when a cached result is returned, so a cache hit needs no DNS. These lookups use PHP's resolver (`gethostbynamel()`, `dns_get_record()`), which `http.timeout` does not bound: a slow DNS server delays the request by its own timeout.

**Residual risk — DNS rebinding.** The host is resolved once by this guard and again by the HTTP client, so a hostile DNS server could in theory return a public address to the check and a private one to the actual request (a TOCTOU gap). For high-sensitivity environments, use `url.allowed_hosts` to pin the destinations you trust.

### SVG parsing

SVGs are parsed with `libxml` using `LIBXML_NONET` (no network access) and without entity substitution, so external entities are not expanded. Entity references in `width`, `height` and `viewBox` are rejected before they are read: libxml would expand them there without limit. Content that starts with markup always goes to this parser, never to `getimagesize()` (which parses SVG itself on PHP 8.5, without these checks). This package only reads the root element's geometry; it does **not** sanitise SVG markup for safe rendering. If you serve user-supplied SVGs to browsers, sanitise them separately.

## Formats

The format is recognised from the contents, never from the file name, extension or MIME type: a PNG named `logo.svg` is read as a PNG.

- **Raster formats** are measured by PHP's `getimagesize()`: PNG, JPEG, GIF, WebP, BMP, and the others it knows.
- **HEIF/HEIC** is read by the package itself, on every PHP version, from the primary image's `ispe` size cropped by its `clap` clean aperture. PHP 8.5's `getimagesize()` reports the uncropped coded size instead (64x64 for a 33x17 photo). A download stops once the metadata has arrived.
- **SVG** is read from `width` and `height`, in CSS absolute units, or from the `viewBox`. When only one of them is given, the other follows the `viewBox` aspect ratio, as in browsers. An explicit `0` is an error. Gzip-compressed SVG (`.svgz`) is not supported.
- **WBMP** is not accepted. It has no signature, so almost any bytes starting with two zero bytes would pass for one.

Dimensions are those stored in the file. Rotation is not applied: not EXIF orientation in a JPEG, and not the `irot` and `imir` properties in a HEIF image. A photo taken in portrait may therefore be reported as landscape.

## Configuration

The published `config/image-dimensions.php` exposes the following options (each also reads an environment variable).

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `remote_read_bytes` | `IMAGE_DIMENSIONS_REMOTE_READ_BYTES` | `131072` (128KB) | Bytes read from a remote source before the first attempt to determine dimensions. Clamped to 8KB–1MB. |
| `max_download_bytes` | `IMAGE_DIMENSIONS_MAX_DOWNLOAD_BYTES` | `33554432` (32MB) | Hard cap on how many bytes are read from any non-local source — URLs, storage streams, `fromContents()`, and `fromStream()`. Exceeding it throws `FileTooLargeException`. Never applied below `remote_read_bytes` (the header probe must fit). `0` disables the limit (not recommended). |
| `temp_dir` | `IMAGE_DIMENSIONS_TEMP_DIR` | `null` | Directory for temporary files. `null` or empty means `sys_get_temp_dir()`, as it is when a lookup runs (not when the config was cached). Must exist and be writable. |
| `enable_cache` | `IMAGE_DIMENSIONS_ENABLE_CACHE` | `true` | Whether to cache results. |
| `cache_ttl` | `IMAGE_DIMENSIONS_CACHE_TTL` | `3600` | Cache lifetime in seconds. `null` caches forever; `0` or less disables caching for these lookups. |
| `http.timeout` | `IMAGE_DIMENSIONS_HTTP_TIMEOUT` | `60` | Deadline for the whole request in seconds, headers and body; fractions allowed. `0` means none. |
| `http.connect_timeout` | `IMAGE_DIMENSIONS_HTTP_CONNECT_TIMEOUT` | `10` | Connection timeout in seconds; fractions allowed. |
| `http.verify_ssl` | `IMAGE_DIMENSIONS_HTTP_VERIFY_SSL` | `true` | Verify TLS certificates. |
| `url.allow_private_hosts` | `IMAGE_DIMENSIONS_URL_ALLOW_PRIVATE_HOSTS` | `false` | Allow URLs that resolve to private/reserved addresses. Leave `false` for SSRF protection. |
| `url.allowed_hosts` | `IMAGE_DIMENSIONS_URL_ALLOWED_HOSTS` | `[]` | Comma-separated strict allowlist of hostnames. When non-empty, only these hosts may be fetched. Internationalized names match their punycode form. |
| `url.max_redirects` | `IMAGE_DIMENSIONS_URL_MAX_REDIRECTS` | `5` | Maximum redirects to follow; each hop is re-validated. |
| `svg.max_file_size` | `IMAGE_DIMENSIONS_SVG_MAX_SIZE` | `10485760` (10MB) | Maximum SVG size before `FileTooLargeException` is thrown. |

Values are read as environment variables spell them. An empty or unrecognised value gives the default: an empty `IMAGE_DIMENSIONS_MAX_DOWNLOAD_BYTES=` keeps the 32MB limit rather than removing it. Booleans accept `true`/`false`, `1`/`0`, `yes`/`no` and `on`/`off`.

The cache key is derived from the source type and its identifier. For a local file, that is the path plus its size, modification and change times, and inode, so a file rewritten within the same second, or replaced by one with the same modification time, is measured again. For a storage file, it is the disk, the path and the modification time; a disk that cannot report the time is cached by path alone. A URL is keyed in its [normal form](#from-a-remote-url). Because a changed file produces a new key, the superseded entry is left to expire on its own; with `cache_ttl` set to `null` nothing expires, so prefer a finite TTL for frequently rewritten files.

An error of the cache store itself (an unreachable Redis, say) is thrown as it is, by `from*` and `tryFrom*` alike. An entry that is not a valid result is treated as a miss and overwritten.

`fromContents()`, `fromStream()`, and `fromUploadedFile()` are never cached — none of them has a stable identity to key on. (In particular, PHP recycles upload temp filenames, so caching them by path could return another request's dimensions.)

### Temporary files

A URL download is written to a temporary file in `temp_dir`, and so is the rest of a stream or storage file whose first `remote_read_bytes` did not give the dimensions. Contents and the header of a stream are analysed in memory. Temporary files are named `imgdim_*`, readable by their owner only (on Unix-like systems), and removed as soon as the lookup ends, or at the latest when the process shuts down. Only a process that is killed can leave one behind. Such leftovers can be deleted from `temp_dir` once they are older than any lookup can take, say an hour.

## Testing

```bash
composer test       # PHPUnit
composer analyse    # PHPStan (level 8)
composer format     # Pint (apply)
composer check      # lint + analyse + test
```

## Contributing

Contributions are welcome. Please open a pull request and make sure `composer check` passes.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md) for details.
