<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\Exceptions\FileTooLargeException;
use Jackardios\ImageDimensions\Facades\ImageDimensions;
use Jackardios\ImageDimensions\Providers\ImageDimensionsServiceProvider;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The package inside a Laravel application: the facade, the published
 * config, local disks and the cache stores.
 */
class ImageDimensionsIntegrationTest extends TestCase
{
    #[Test]
    public function the_facade_reads_dimensions(): void
    {
        $this->assertDimensions(800, 600, ImageDimensions::fromLocal($this->createImage('image.png', 800, 600)));
    }

    #[Test]
    public function it_publishes_the_configuration_file(): void
    {
        // Inspect the registration instead of running vendor:publish, which
        // would write into the testbench skeleton shared by parallel runs.
        $paths = ServiceProvider::pathsToPublish(ImageDimensionsServiceProvider::class, 'image-dimensions-config');

        $this->assertSame(
            [realpath(__DIR__.'/../../config/image-dimensions.php') => config_path('image-dimensions.php')],
            array_combine(array_map('realpath', array_keys($paths)), $paths)
        );
    }

    #[Test]
    public function the_container_builds_the_service_from_the_application_config(): void
    {
        Config::set('image-dimensions.enable_cache', false);
        Config::set('image-dimensions.max_download_bytes', 10000);
        Config::set('image-dimensions.remote_read_bytes', 8192);
        $keys = $this->recordCacheWrites();

        $this->assertDimensions(80, 60, ImageDimensions::fromLocal($this->createImage('image.png', 80, 60)));
        $this->assertCount(0, $keys, 'enable_cache is read from the config.');

        $this->expectException(FileTooLargeException::class);
        $this->expectExceptionMessage('max 10000 bytes');
        ImageDimensions::fromContents(str_repeat('x', 10001));
    }

    #[Test]
    public function it_reads_a_file_on_a_local_disk(): void
    {
        Config::set('filesystems.disks.images', ['driver' => 'local', 'root' => $this->tempPath.'/disk']);
        $this->app['filesystem']->disk('images')->put('photos/image.png', $this->imageBytes(800, 600));

        $this->assertDimensions(800, 600, ImageDimensions::fromStorage('images', 'photos/image.png'));
    }

    #[Test]
    public function a_missing_file_on_a_local_disk_is_not_found(): void
    {
        Config::set('filesystems.disks.images', ['driver' => 'local', 'root' => $this->tempPath.'/disk']);

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage("File not found on disk 'images': missing.png");
        ImageDimensions::fromStorage('images', 'missing.png');
    }

    #[Test]
    public function it_works_with_different_cache_stores(): void
    {
        $path = $this->createImage('image.png', 800, 600);

        // The file store lives in this test's temporary directory (see
        // TestCase::defineEnvironment()), so flushing it touches nothing else.
        foreach (['array', 'file'] as $store) {
            Config::set('cache.default', $store);
            Cache::flush();
            $keys = $this->recordCacheWrites();

            $this->assertDimensions(800, 600, ImageDimensions::fromLocal($path));
            $this->assertCount(1, $keys, "One entry written to the {$store} store.");
            $this->assertSame(['width' => 800, 'height' => 600], Cache::get($keys[0]));
        }
    }
}
