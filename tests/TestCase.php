<?php

namespace Jackardios\ImageDimensions\Tests;

use ErrorException;
use Illuminate\Filesystem\FilesystemAdapter as IlluminateFilesystemAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Jackardios\ImageDimensions\Providers\ImageDimensionsServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Per-test scratch directory, removed (recursively) in tearDown.
     */
    protected string $tempPath;

    protected function setUp(): void
    {
        $this->tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'imgdim_test_'.bin2hex(random_bytes(6));
        mkdir($this->tempPath, 0777, true);

        parent::setUp();

        $this->failOnOwnDeprecations();

        // A test that forgets Http::fake() must never reach the network.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        restore_error_handler();

        try {
            parent::tearDown();
        } finally {
            // Also when Mockery's expectation checks throw in tearDown.
            $this->removeDirectory($this->tempPath);
        }
    }

    /**
     * Laravel sends deprecations to a log channel, so PHPUnit never sees
     * them. Throw instead, but only for deprecations this package causes:
     * withoutDeprecationHandling() would also fail on the framework's own
     * (e.g. spl_object_hash() on PHP 8.6), which are not ours to fix.
     */
    private function failOnOwnDeprecations(): void
    {
        $previous = null;
        $previous = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use (&$previous) {
            if (($level & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0 && $this->isOwnDeprecation($file)) {
                throw new ErrorException($message, 0, $level, $file, $line);
            }

            // Pass the return value through: only a literal false makes PHP
            // run its standard handler as well.
            return $previous !== null ? $previous($level, $message, $file, $line) : false;
        });
    }

    private function isOwnDeprecation(string $file): bool
    {
        $root = dirname(__DIR__).DIRECTORY_SEPARATOR;
        $isOwn = static fn (string $path): bool => str_starts_with($path, $root.'src'.DIRECTORY_SEPARATOR)
            || str_starts_with($path, $root.'tests'.DIRECTORY_SEPARATOR);

        if ($isOwn($file)) {
            return true;
        }

        // A deprecated library API: blame whoever called the function that
        // raised it via trigger_error() or Symfony's trigger_deprecation().
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        foreach ($frames as $i => $frame) {
            if (($frame['function'] ?? null) === 'trigger_error') {
                $caller = $frames[$i + 1] ?? [];
                if (($caller['function'] ?? null) === 'trigger_deprecation') {
                    $caller = $frames[$i + 2] ?? [];
                }

                return $isOwn($caller['file'] ?? '');
            }
        }

        return false;
    }

    protected function getPackageProviders($app): array
    {
        return [ImageDimensionsServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Keep the file cache store out of the shared testbench skeleton so
        // parallel test processes cannot see each other's entries.
        $app['config']->set('cache.stores.file.path', $this->tempPath.DIRECTORY_SEPARATOR.'cache');
    }

    protected function createImage(string $filename, int $width, int $height, string $format = 'png'): string
    {
        $path = $this->tempPath.DIRECTORY_SEPARATOR.$filename;
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));

        match ($format) {
            'jpg', 'jpeg' => imagejpeg($image, $path, 95),
            'gif' => imagegif($image, $path),
            'webp' => imagewebp($image, $path, 95),
            'bmp' => imagebmp($image, $path),
            default => imagepng($image, $path),
        };

        return $path;
    }

    /**
     * @param  array<string, string|int>  $attributes  Rendered onto the root <svg> element.
     */
    protected function createSvg(string $filename, array $attributes, string $content = ''): string
    {
        $attrs = '';
        foreach ($attributes as $key => $value) {
            $attrs .= " {$key}=\"{$value}\"";
        }

        return $this->createFile(
            $filename,
            '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<svg xmlns="http://www.w3.org/2000/svg"'.$attrs.'>'
            .($content !== '' ? $content : '<rect width="100%" height="100%" fill="red"/>')
            .'</svg>'
        );
    }

    protected function createFile(string $filename, string $contents): string
    {
        $path = $this->tempPath.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Register a non-local (in-memory) disk, so fromStorage() takes the
     * streaming path instead of delegating to fromLocal().
     */
    protected function useInMemoryDisk(string $name, ?FilesystemAdapter $adapter = null): IlluminateFilesystemAdapter
    {
        $adapter ??= new InMemoryFilesystemAdapter;
        $disk = new IlluminateFilesystemAdapter(new Filesystem($adapter), $adapter);
        Storage::set($name, $disk);

        return $disk;
    }

    /**
     * Make a path unreadable, or skip the test where permissions are not
     * enforced (running as root, or on Windows where chmod cannot deny reads).
     */
    protected function makeUnreadable(string $path): void
    {
        chmod($path, 0000);
        clearstatcache(true, $path);

        if (is_readable($path)) {
            chmod($path, 0644);
            $this->markTestSkipped('File permissions are not enforced in this environment.');
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (is_link($path)) {
                // A link to a directory is removed with rmdir() on Windows.
                @unlink($path) || @rmdir($path);
            } elseif (is_dir($path)) {
                @chmod($path, 0777);
                $this->removeDirectory($path);
            } else {
                @chmod($path, 0644);
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
