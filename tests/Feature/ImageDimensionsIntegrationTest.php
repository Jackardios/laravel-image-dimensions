<?php

namespace Jackardios\ImageDimensions\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Jackardios\ImageDimensions\Facades\ImageDimensions;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Providers\ImageDimensionsServiceProvider;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ImageDimensionsIntegrationTest extends TestCase
{
    #[Test]
    public function it_registers_the_service_provider_and_facade(): void
    {
        $this->assertInstanceOf(ImageDimensionsService::class, $this->app->make('image-dimensions'));
        $this->assertSame($this->app->make('image-dimensions'), ImageDimensions::getFacadeRoot());
    }

    #[Test]
    public function it_correctly_uses_the_facade_to_get_dimensions(): void
    {
        $this->assertSame(['width' => 800, 'height' => 600], ImageDimensions::fromLocal($this->createImage('test.png', 800, 600)));
    }

    #[Test]
    public function it_merges_the_package_configuration(): void
    {
        $this->assertSame(131072, config('image-dimensions.remote_read_bytes'));
        $this->assertSame(10485760, config('image-dimensions.svg.max_file_size'));
    }

    #[Test]
    public function it_registers_the_configuration_file_for_publishing(): void
    {
        // Inspect the publish map instead of running vendor:publish, which
        // would write into the testbench skeleton shared by parallel runs.
        $paths = ServiceProvider::pathsToPublish(ImageDimensionsServiceProvider::class, 'image-dimensions-config');

        $this->assertCount(1, $paths);
        $this->assertFileExists(array_key_first($paths));
        $this->assertSame(config_path('image-dimensions.php'), reset($paths));
        $this->assertSame(
            require array_key_first($paths),
            require dirname(__DIR__, 2).'/config/image-dimensions.php'
        );
    }

    #[Test]
    public function it_gets_dimensions_from_a_local_laravel_storage_disk(): void
    {
        Storage::fake('images');
        Storage::disk('images')->put('test.png', file_get_contents($this->createImage('test.png', 800, 600)));

        $this->assertSame(['width' => 800, 'height' => 600], ImageDimensions::fromStorage('images', 'test.png'));
    }

    #[Test]
    public function it_gets_dimensions_from_a_non_local_laravel_storage_disk(): void
    {
        $disk = $this->useInMemoryDisk('remote');
        $disk->put('images/test.png', file_get_contents($this->createImage('test.png', 800, 600)));
        $disk->put('images/test.svg', '<svg width="1920" height="1080" xmlns="http://www.w3.org/2000/svg"/>');

        $this->assertSame(['width' => 800, 'height' => 600], ImageDimensions::fromStorage('remote', 'images/test.png'));
        $this->assertSame(['width' => 1920, 'height' => 1080], ImageDimensions::fromStorage('remote', 'images/test.svg'));
    }

    #[Test]
    public function it_works_with_different_cache_stores(): void
    {
        $path = $this->createImage('test.png', 800, 600);
        $key = 'image_dimensions:local:'.md5(realpath($path)).':'.filemtime($path);

        foreach (['array', 'file'] as $store) {
            config(['cache.default' => $store]);
            Cache::flush();

            $this->assertSame(['width' => 800, 'height' => 600], ImageDimensions::fromLocal($path), $store);
            $this->assertSame(['width' => 800, 'height' => 600], Cache::get($key), $store);
        }
    }

    #[Test]
    public function it_returns_the_cached_value_until_the_file_changes(): void
    {
        config(['cache.default' => 'array']);
        $path = $this->createImage('test.png', 800, 600);
        $key = 'image_dimensions:local:'.md5(realpath($path)).':'.filemtime($path);
        Cache::put($key, ['width' => 1, 'height' => 1], 60);

        $this->assertSame(['width' => 1, 'height' => 1], ImageDimensions::fromLocal($path));

        touch($path, filemtime($path) + 10);
        clearstatcache();

        $this->assertSame(['width' => 800, 'height' => 600], ImageDimensions::fromLocal($path));
    }

    #[Test]
    public function it_falls_back_to_viewbox_for_svg_with_percentage_dimensions(): void
    {
        $path = $this->createFile('percentage.svg', '<svg width="100%" height="100%" viewBox="0 0 200 150"><rect width="100%" height="100%"/></svg>');

        $this->assertSame(['width' => 200, 'height' => 150], ImageDimensions::fromLocal($path));
    }
}
