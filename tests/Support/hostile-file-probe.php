<?php declare(strict_types=1);

/**
 * Measures a hostile file in a process of its own, so that running out of
 * memory, which cannot be caught, kills this process and not the test suite.
 *
 * Usage: php -d memory_limit=64M hostile-file-probe.php <autoload.php> <file>
 * Prints "rejected" when the file is refused as it should be.
 */

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\ImageDimensionsService;

[, $autoload, $path] = $argv;

require $autoload;

// The service reads its settings with config().
$container = new Container;
$container->instance('config', new Repository(['image-dimensions' => ['enable_cache' => false]]));
Container::setInstance($container);

try {
    $size = (new ImageDimensionsService)->fromLocal($path);
    echo "read as {$size['width']}x{$size['height']}";
} catch (InvalidImageException) {
    echo 'rejected';
}
