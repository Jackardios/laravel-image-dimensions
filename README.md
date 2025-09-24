# Laravel Image Dimensions

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jackardios/laravel-image-dimensions.svg?style=flat-square)](https://packagist.org/packages/jackardios/laravel-image-dimensions)
[![Tests](https://github.com/Jackardios/laravel-image-dimensions/actions/workflows/tests.yml/badge.svg)](https://github.com/Jackardios/laravel-image-dimensions/actions/workflows/tests.yml)

A robust and efficient Laravel package to get the dimensions (width and height) of images from various sources. It's designed to be fast, reliable, and easy to use, with built-in support for caching and optimized handling of remote files.

### Features

-   **Multiple Sources**: Get dimensions from local file paths, remote URLs, and Laravel Storage disks.
-   **Wide Format Support**: Supports common image formats like PNG, JPEG, GIF, WebP, and BMP.
-   **Advanced SVG Parsing**: Correctly determines dimensions from SVGs, including those using `viewBox` or percentage-based sizes.
-   **Optimized Remote Fetching**: Reads a minimal portion of remote files first, avoiding large downloads when possible.
-   **Built-in Caching**: Automatically caches image dimensions to boost performance for repeated requests.
-   **Laravel Native**: Seamless integration with Laravel's Filesystem, Cache, and HTTP Client.
-   **Secure**: Includes basic sanitization for SVG files to prevent XSS vulnerabilities.

## Requirements

-   PHP 8.1+
-   Laravel 10.x, 11.x, or 12.x

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

### SVG Handling

-   `svg.max_file_size`: The maximum allowed file size (in bytes) for SVG files to prevent parsing of excessively large files. Default: `10485760` (10MB).

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