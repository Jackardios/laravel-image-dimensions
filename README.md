# Laravel Image Dimensions

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jackardios/laravel-image-dimensions.svg?style=flat-square)](https://packagist.org/packages/jackardios/laravel-image-dimensions)
[![Tests](https://github.com/Jackardios/laravel-image-dimensions/actions/workflows/tests.yml/badge.svg)](https://github.com/Jackardios/laravel-image-dimensions/actions/workflows/tests.yml)

A robust and efficient Laravel package to get the dimensions (width and height) of images from various sources. It's designed to be fast, reliable, and easy to use, with built-in support for caching and optimized handling of remote files.

### Features

-   **Multiple Sources**: Get dimensions from local file paths, remote URLs, and Laravel Storage disks.
-   **Wide Format Support**: Supports common image formats like PNG, JPEG, GIF, WebP, and BMP.
-   **Advanced SVG Parsing**: Determines dimensions from SVGs, including absolute units (`in`, `cm`, `mm`, `pt`, `pc`), `viewBox`, and percentage-based sizes.
-   **Optimized Remote Fetching**: Reads a minimal portion of remote files first, avoiding large downloads when possible.
-   **Built-in Caching**: Automatically caches image dimensions to boost performance for repeated requests.
-   **Laravel Native**: Seamless integration with Laravel's Filesystem, Cache, and HTTP Client.
-   **Safe SVG Handling**: SVG markup is parsed without network access and without loading or expanding entities.

## Requirements

-   PHP 8.1 – 8.5
-   Laravel 10.x, 11.x, 12.x, or 13.x (each on the PHP versions it supports)
-   Guzzle 7.5+ or 8.0.1+. The low minimum is for applications that cannot update Guzzle; releases below 7.15.2, and 8.0.0, have published security advisories, so install 7.15.2 or later where you can.

## Installation

You can install the package via Composer:

```bash
composer require jackardios/laravel-image-dimensions
```

The service provider and facade will be automatically registered.

To publish the configuration file, run:

```bash
php artisan vendor:publish --tag="image-dimensions-config"
```

This will create a `config/image-dimensions.php` file where you can customize the package settings.

## Usage

The package provides a simple and consistent API to get image dimensions from different sources. All methods return an associative array `['width' => int, 'height' => int]` on success or throw an exception on failure.

### Using the Facade

The easiest way to use the package is through the `ImageDimensions` facade.

#### From a Local File Path

Provide an absolute path to a file on your server.

```php
use Jackardios\ImageDimensions\Facades\ImageDimensions;

$path = public_path('images/my-image.png');

try {
    $dimensions = ImageDimensions::fromLocal($path);
    // $dimensions -> ['width' => 800, 'height' => 600]
} catch (\Exception $e) {
    // Handle exceptions like FileNotFoundException or InvalidImageException
}
```

#### From a Remote URL

Provide a public URL to an image. Only `http` and `https` schemes are supported.

> **Do not pass URLs from users to `fromUrl()` on 1.x.** This version requests whatever host the URL names, including private addresses, `localhost` and cloud metadata endpoints, follows redirects without checking them, and, when the first `remote_read_bytes` are not enough, downloads the whole response into memory without a size limit. Validate the URL yourself first, or use [version 2](https://github.com/Jackardios/laravel-image-dimensions), which blocks private hosts by default (`url.allow_private_hosts`, `url.allowed_hosts`) and caps the download (`max_download_bytes`). Version 2 requires PHP 8.2 and Laravel 12 or 13.

```php
use Jackardios\ImageDimensions\Facades\ImageDimensions;

$url = 'https://example.com/path/to/image.jpg';

try {
    $dimensions = ImageDimensions::fromUrl($url);
    // $dimensions -> ['width' => 1920, 'height' => 1080]
} catch (\Exception $e) {
    // Handle exceptions like UrlAccessException or InvalidImageException
}
```

#### From Laravel Storage

Provide the disk name and the path to the file within that disk. This works for both local and cloud-based storage drivers (like `s3`).

```php
use Jackardios\ImageDimensions\Facades\ImageDimensions;

// Example with a local disk
$dimensions = ImageDimensions::fromStorage('public', 'uploads/avatar.png');

// Example with an S3 disk
$dimensions = ImageDimensions::fromStorage('s3', 'images/banner.svg');
```

### Exception Handling

The package throws specific exceptions to allow for fine-grained error handling:

-   `FileNotFoundException`: The file does not exist at the specified local or storage path.
-   `UrlAccessException`: The URL could not be accessed (e.g., 404 error, network timeout).
-   `InvalidImageException`: The file is not a valid or supported image, or its dimensions could not be determined.
-   `StorageAccessException`: The file stream or content could not be read from the storage disk.
-   `TemporaryFileException`: A temporary file could not be created or written to, often due to permissions issues.

## Configuration

After publishing the configuration file, you can modify the settings in `config/image-dimensions.php`.

### Caching

Caching is enabled by default to improve performance.

-   `enable_cache`: Set to `true` to enable caching, `false` to disable it.
-   `cache_ttl`: The duration (in seconds) to cache dimensions. The default is `3600` (1 hour).

The cache key is generated based on the source type, identifier (path/URL), and file modification time (for local/storage files), ensuring the cache is automatically invalidated when a file changes.

### Remote File Handling

-   `remote_read_bytes`: The number of bytes to initially read from a remote source (URL or cloud storage). This allows the package to get dimensions from the image header without downloading the entire file. Default: `131072` (128KB).
-   `http`: Standard Laravel HTTP Client options like `timeout`, `connect_timeout`, and `verify_ssl`.

### Formats

Raster images are measured by PHP's `getimagesize()`. Two kinds of files never reach it, and throw an `InvalidImageException`:

-   Flash files (`FWS` and compressed `CWS`). They are not images, and `getimagesize()` inflates a compressed one whole to find a size, so 100KB could exhaust the memory limit.
-   Files larger than 8MB that `getimagesize()` does not recognise by a signature, including ISO media files (`ftyp`) that are not AVIF, such as MP4. `getimagesize()` reads them as WBMP or XBM, and may read all of such a file into memory. Of a remote file, the first `remote_read_bytes` are measured before the whole of it is downloaded, and those are never that large. Smaller WBMP and XBM images are read as before, which also means that a text file of up to 8MB with two `#define` lines is measured as an XBM image.

A width or height above 2147483647 is an error, whatever the format.

Measuring is not always a matter of the first bytes. JPEG, JPEG 2000 and IFF are a chain of segments that `getimagesize()` walks until it finds the size, so a file built of thousands of tiny segments takes time in proportion to its size, without using more memory: about 20–30 seconds for 80MB of 4-byte JPEG segments. `fromUrl()` and `fromStorage()` download a file of any size in 1.x, so limit the size of what you measure yourself, or use 2.x and its `max_download_bytes`.

### SVG Handling

-   `svg.max_file_size`: The maximum allowed file size (in bytes) for SVG files to prevent parsing of excessively large files. Default: `10485760` (10MB).

Any file whose content starts with markup (`<`) is treated as SVG, whatever its extension or MIME type. Dimensions are resolved from the root `<svg>` element as follows:

-   `width` and `height` in pixels, either unitless or with an absolute unit (`px`, `in`, `cm`, `mm`, `pt`, `pc`, at 96 DPI). Fractional values are rounded up.
-   A side given as a percentage, a relative unit (`em`, `ex`, …) or `auto`, or left out, is taken from the `viewBox`. If only one side is known, the other follows the `viewBox` aspect ratio, as in a browser.
-   An explicit `0` width or height, or no usable size at all, throws an `InvalidImageException`.
-   Entity references inside `width`, `height` or `viewBox` are rejected, because expanding them can take quadratic time.

## Testing

```bash
composer test
```

## Contributing

Contributions are welcome! Please feel free to submit a pull request for any bug fixes or improvements.

1.  Fork the repository.
2.  Create a new branch (`git checkout -b feature/my-new-feature`).
3.  Make your changes.
4.  Ensure the tests pass (`composer test`).
5.  Commit your changes (`git commit -am 'Add some feature'`).
6.  Push to the branch (`git push origin feature/my-new-feature`).
7.  Create a new Pull Request.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.