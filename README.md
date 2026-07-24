# Laravel Image Dimensions

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jackardios/laravel-image-dimensions.svg?style=flat-square)](https://packagist.org/packages/jackardios/laravel-image-dimensions)
[![Tests](https://github.com/Jackardios/laravel-image-dimensions/actions/workflows/tests.yml/badge.svg)](https://github.com/Jackardios/laravel-image-dimensions/actions/workflows/tests.yml)

A robust and efficient Laravel package to get the dimensions (width and height) of images from local files, URLs, Laravel Storage disks, raw contents, streams, and uploaded files. It reads only as much of a remote file as it needs, caches results, and guards outgoing requests against SSRF.

## Features

- **Multiple sources** — local paths, remote URLs, Storage disks, raw binary contents, open streams, and uploaded files.
- **Wide format support** — PNG, JPEG, GIF, WebP, BMP, and other formats understood by `getimagesize()`, plus a dedicated SVG parser.
- **Correct SVG parsing** — reads `width`/`height` (with CSS absolute units) and falls back to `viewBox`, including namespaced roots (`<svg:svg>`) and documents with an internal DTD subset.
- **Bounded remote fetching** — reads a small header chunk first and only continues on the *same* connection when necessary, capped by a configurable download limit.
- **Built-in caching** — results are cached and automatically invalidated when a file changes.
- **SSRF protection** — `fromUrl()` blocks private, loopback, link-local, and reserved addresses by default, and re-validates every redirect hop.
- **Typed result** — returns an immutable `Dimensions` value object that still behaves like the old `['width' => ..., 'height' => ...]` array.

## Requirements

- PHP 8.2+
- Laravel 11.x, 12.x, or 13.x

> Upgrading from v1? See [UPGRADE.md](UPGRADE.md). v1.x supports Laravel 10/11/12 and PHP 8.1+.

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
// ... write image bytes into $stream, rewind ...
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

`tryFrom*` swallows only the package's own exceptions (`ImageDimensionsException` and its subclasses). Programming errors such as passing a non-resource to `fromStream()` still surface.

### Dependency injection

The service is a singleton bound to the concrete class, the contract, and the `image-dimensions` alias — inject whichever you prefer:

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
- By default, hosts that resolve to private, loopback, link-local, or reserved ranges — including the cloud metadata endpoint `169.254.169.254`, CGNAT (`100.64.0.0/10`), and the IETF test networks — are rejected for both IPv4 and IPv6. This covers IPv4-mapped IPv6 (`::ffff:`) as well as addresses that tunnel an IPv4 destination inside IPv6: 6to4 (`2002::/16`) and NAT64 (`64:ff9b::/96`). Set `url.allow_private_hosts` to `true` to restore the old, unrestricted behaviour (not recommended).
- Every redirect hop is re-validated against the same rules, up to `url.max_redirects`.
- Setting `url.allowed_hosts` switches to a strict allowlist: only the listed hostnames may be fetched.

**Residual risk — DNS rebinding.** The host is resolved once by this guard and again by the HTTP client, so a hostile DNS server could in theory return a public address to the check and a private one to the actual request (a TOCTOU gap). For high-sensitivity environments, use `url.allowed_hosts` to pin the destinations you trust.

### SVG parsing

SVGs are parsed with `libxml` using `LIBXML_NONET` (no network access) and without entity substitution, so external entities are not expanded and libxml's built-in limits neutralise entity-expansion ("billion laughs") attacks. This package only reads the root element's geometry; it does **not** sanitise SVG markup for safe rendering. If you serve user-supplied SVGs to browsers, sanitise them separately.

## Configuration

The published `config/image-dimensions.php` exposes the following options (each also reads an environment variable).

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `remote_read_bytes` | `IMAGE_DIMENSIONS_REMOTE_READ_BYTES` | `131072` (128KB) | Bytes read from a remote source before the first attempt to determine dimensions. Clamped to 8KB–1MB. |
| `max_download_bytes` | `IMAGE_DIMENSIONS_MAX_DOWNLOAD_BYTES` | `33554432` (32MB) | Hard cap on how many bytes are read from any non-local source — URLs, storage streams, `fromContents()`, and `fromStream()`. Exceeding it throws `FileTooLargeException`. Never applied below `remote_read_bytes` (the header probe must fit). `0` disables the limit (not recommended). |
| `temp_dir` | `IMAGE_DIMENSIONS_TEMP_DIR` | `sys_get_temp_dir()` | Directory for temporary files created while streaming remote images. Must exist and be writable. |
| `enable_cache` | `IMAGE_DIMENSIONS_ENABLE_CACHE` | `true` | Whether to cache results. |
| `cache_ttl` | `IMAGE_DIMENSIONS_CACHE_TTL` | `3600` | Cache lifetime in seconds. `null` caches forever; `0` or less disables caching for these lookups. |
| `http.timeout` | `IMAGE_DIMENSIONS_HTTP_TIMEOUT` | `60` | Request timeout in seconds. |
| `http.connect_timeout` | `IMAGE_DIMENSIONS_HTTP_CONNECT_TIMEOUT` | `10` | Connection timeout in seconds. |
| `http.verify_ssl` | `IMAGE_DIMENSIONS_HTTP_VERIFY_SSL` | `true` | Verify TLS certificates. |
| `url.allow_private_hosts` | `IMAGE_DIMENSIONS_URL_ALLOW_PRIVATE_HOSTS` | `false` | Allow URLs that resolve to private/reserved addresses. Leave `false` for SSRF protection. |
| `url.allowed_hosts` | `IMAGE_DIMENSIONS_URL_ALLOWED_HOSTS` | `[]` | Comma-separated strict allowlist of hostnames. When non-empty, only these hosts may be fetched. |
| `url.max_redirects` | `IMAGE_DIMENSIONS_URL_MAX_REDIRECTS` | `5` | Maximum redirects to follow; each hop is re-validated. |
| `svg.max_file_size` | `IMAGE_DIMENSIONS_SVG_MAX_SIZE` | `10485760` (10MB) | Maximum SVG size before `FileTooLargeException` is thrown. |

The cache key is derived from the source type, its identifier (path/URL), and — for local and storage files — the modification time, so the cache invalidates automatically when a file changes. Because a changed file produces a new key, the superseded entry is left to expire on its own; with `cache_ttl` set to `null` nothing expires, so prefer a finite TTL for frequently rewritten files.

`fromContents()`, `fromStream()`, and `fromUploadedFile()` are never cached — none of them has a stable identity to key on. (In particular, PHP recycles upload temp filenames, so caching them by path could return another request's dimensions.)

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
