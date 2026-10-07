<?php

declare(strict_types=1);

/**
 * Measures a hostile file in a process of its own, so that running out of
 * memory, which cannot be caught, kills this process and not the test suite.
 *
 * Usage: php -d memory_limit=64M hostile-file-probe.php <autoload.php> <method> <file or URL>
 * Prints "rejected" when the file is refused as it should be.
 */

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Orchestra\Testbench\Foundation\Application;

[, $autoload, $method, $source] = $argv;

require $autoload;

// Storage disks and the HTTP client come from the application.
if (in_array($method, ['fromOtherDisk', 'fromUrl'], true)) {
    Application::create();
}

$service = new ImageDimensionsService([
    'enable_cache' => false,
    'url' => ['allow_private_hosts' => true],
    'http' => ['timeout' => 20, 'connect_timeout' => 5],
]);

try {
    $result = match ($method) {
        'fromLocal' => $service->fromLocal($source),
        'fromContents' => $service->fromContents((string) file_get_contents($source)),
        'fromStream' => $service->fromStream(fopen($source, 'rb')),
        'fromOtherDisk' => (static function () use ($service, $source) {
            $adapter = new InMemoryFilesystemAdapter;
            Storage::set('probe', $disk = new FilesystemAdapter(new Filesystem($adapter), $adapter));
            $disk->put('file.bin', (string) file_get_contents($source));

            return $service->fromStorage('probe', 'file.bin');
        })(),
        'fromUrl' => $service->fromUrl($source),
    };
} catch (InvalidImageException) {
    $result = null;
}

echo $result === null ? 'rejected' : "read as {$result}";
