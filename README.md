# Laravel Image Dimensions

A Laravel package to efficiently determine image dimensions (width and height) from local files, URLs, and Laravel storage.

This package aims to quickly retrieve image dimensions without downloading the entire file, especially for remote images, by reading only the necessary initial bytes.

## Features

-   **Local Files**: Determine dimensions from images stored on the local filesystem.
-   **Remote URLs**: Efficiently get dimensions from images via URL by reading only the initial bytes (e.g., first 64KB).
-   **Laravel Storage**: Support for images stored in Laravel's configured filesystems (e.g., `public`, `s3`).
-   **Broad Image Format Support**: Leverages PHP's `getimagesize()` function, which supports a wide range of image formats (JPEG, PNG, GIF, BMP, WebP, etc.).
-   **Fast and Memory Efficient**: Designed to minimize memory consumption and execution time, particularly for remote files.

## Installation

You can install the package via Composer:

```bash
composer require jackardios/laravel-image-dimensions
```

You can also add the Facade alias:

```php
// config/app.php

'aliases' => [
    // ...
    'ImageDimensions' => Jackardios\ImageDimensions\Facades\ImageDimensions::class,
],
```

Publish the configuration file using the Artisan command:

```bash
php artisan vendor:publish --provider="Jackardios\\ImageDimensions\\Providers\\ImageDimensionsServiceProvider" --tag="image-dimensions-config"
```

This will publish `config/image-dimensions.php`.

## Configuration

The published configuration file (`config/image-dimensions.php`) allows you to customize the package's behavior:

```php
<?php

return [
    /*
     * The maximum number of bytes to read from a remote image when determining its dimensions.
     * This helps to quickly determine dimensions without downloading the entire file.
     */
    'remote_read_bytes' => 65536,

    /*
     * Path to a temporary directory where remote image data will be stored.
     * Ensure this directory is writable by the web server.
     */
    'temp_dir' => sys_get_temp_dir(),
];
```

-   `remote_read_bytes`: Defines how many bytes to read from remote or storage files. `getimagesize()` typically needs only a small header to determine dimensions. 65536 bytes (64KB) is usually more than enough.
-   `temp_dir`: Specifies the directory for temporary files created when reading remote or storage images. Ensure this directory is writable by your web server.

## Usage

The `ImageDimensions` class provides static methods to get image dimensions. Each method returns an `ImageDimensions` object with `width` and `height` properties.

```php
use Jackardios\ImageDimensions\Facades\ImageDimensions;
use Jackardios\ImageDimensions\Exceptions\ImageDimensionsException;

// From a local file path
try {
    $size = ImageDimensions::fromLocal('/path/to/your/image.jpg');
    echo "Width: " . $size['width']; // e.g., 1920
    echo "Height: " . $size['height']; // e.g., 1080
} catch (ImageDimensionsException $e) {
    echo "Error: " . $e->getMessage();
}

// From a URL
try {
    $size = ImageDimensions::fromUrl('https://example.com/images/photo.png');
    echo "Width: " . $size['width'];
    echo "Height: " . $size['height'];
} catch (ImageDimensionsException $e) {
    echo "Error: " . $e->getMessage();
}

// From Laravel Storage disk
// Assuming you have a file 'images/profile.gif' on your 'public' disk
try {
    $size = ImageDimensions::fromStorage('public', 'images/profile.gif');
    echo "Width: " . $size['width'];
    echo "Height: " . $size['height'];
} catch (ImageDimensionsException $e) {
    echo "Error: " . $e->getMessage();
}
```

### Error Handling

Methods will throw specific exceptions that extend `Jackardios\ImageDimensions\Exceptions\ImageDimensionsException`:

-   `FileNotFoundException`: If the local or storage file does not exist.
-   `UrlAccessException`: If the URL is invalid, cannot be opened, or the full content cannot be downloaded.
-   `InvalidImageException`: If the file is not a valid image or its dimensions cannot be determined even after fallback attempts.
-   `TemporaryFileException`: If there are issues creating or writing to temporary files.
-   `StorageAccessException`: If there are issues reading from a Laravel storage stream or getting full content.

## Contributing

Feel free to open issues or submit pull requests on the [GitHub repository](https://github.com/Jackardios/laravel-image-dimensions).

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).


