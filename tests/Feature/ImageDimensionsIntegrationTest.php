<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\TemporaryFileException;
use Jackardios\ImageDimensions\Facades\ImageDimensions;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Providers\ImageDimensionsServiceProvider;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

class ImageDimensionsIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createImage('test.png', 800, 600);
        $this->createSvg('test.svg', ['width' => 1920, 'height' => 1080]);
    }

    #[Test]
    public function it_registers_the_service_provider_and_facade(): void
    {
        $this->assertInstanceOf(ImageDimensionsService::class, $this->app->make('image-dimensions'));
        $this->assertInstanceOf(ImageDimensionsService::class, ImageDimensions::getFacadeRoot());
    }

    #[Test]
    public function it_correctly_uses_the_facade_to_get_dimensions(): void
    {
        $result = ImageDimensions::fromLocal($this->tempPath.'/test.png');

        $this->assertDimensions(800, 600, $result);
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
    public function it_uses_values_from_the_configuration(): void
    {
        Config::set('image-dimensions.remote_read_bytes', 16384);
        Config::set('image-dimensions.enable_cache', false);

        $service = new ImageDimensionsService;
        $reflection = new ReflectionClass($service);

        $remoteReadBytesProp = $reflection->getProperty('remoteReadBytes');
        $this->assertEquals(16384, $remoteReadBytesProp->getValue($service));

        $enableCacheProp = $reflection->getProperty('enableCache');
        $this->assertFalse($enableCacheProp->getValue($service));
    }

    #[Test]
    public function it_gets_dimensions_from_a_local_laravel_storage_disk(): void
    {
        Storage::fake('images');
        Storage::disk('images')->put('test.png', file_get_contents($this->tempPath.'/test.png'));

        $result = ImageDimensions::fromStorage('images', 'test.png');

        $this->assertDimensions(800, 600, $result);
    }

    #[Test]
    public function it_gets_dimensions_from_a_remote_laravel_storage_disk(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('images/test.svg', file_get_contents($this->tempPath.'/test.svg'));

        $result = ImageDimensions::fromStorage('s3', 'images/test.svg');

        $this->assertDimensions(1920, 1080, $result);
    }

    #[Test]
    public function it_throws_exception_if_temp_directory_is_not_writable(): void
    {
        Config::set('image-dimensions.temp_dir', $this->createReadOnlyDirectory('unwritable'));
        $service = new ImageDimensionsService;

        Http::fake(['http://example.com/image.png' => Http::response('image data')]);

        $this->expectException(TemporaryFileException::class);
        $this->expectExceptionMessage('Could not create temporary file');
        $service->fromUrl('http://example.com/image.png');
    }

    #[Test]
    public function it_works_with_different_cache_drivers(): void
    {
        $path = $this->tempPath.'/test.png';

        foreach (['array', 'file'] as $store) {
            Config::set('cache.default', $store);
            Cache::flush();
            $keys = $this->recordCacheWrites();

            $this->assertDimensions(800, 600, ImageDimensions::fromLocal($path));
            $this->assertCount(1, $keys, "One entry written to the {$store} store.");
            $this->assertSame(['width' => 800, 'height' => 600], Cache::get($keys[0]));
        }
    }

    #[Test]
    public function it_handles_malformed_svg_files(): void
    {
        $path = $this->createFile('malformed.svg', '<?xml version="1.0"?><svg><rect>');

        $this->expectException(InvalidImageException::class);
        ImageDimensions::fromLocal($path);
    }

    #[Test]
    public function it_falls_back_to_viewbox_for_svg_with_percentage_dimensions(): void
    {
        $path = $this->createFile(
            'percentage.svg',
            '<svg width="100%" height="100%" viewBox="0 0 200 150"><rect width="100%" height="100%"/></svg>'
        );

        $result = ImageDimensions::fromLocal($path);

        $this->assertDimensions(200, 150, $result);
    }

    #[Test]
    public function it_handles_various_mime_types_and_extensions_correctly(): void
    {
        // Copy the PNG file with the JPG extension
        $sourcePath = $this->tempPath.'/test.png';
        $destPath = $this->tempPath.'/image.jpg';
        copy($sourcePath, $destPath);

        // The library should determine the size by the content, not by the extension
        $result = ImageDimensions::fromLocal($destPath);
        $this->assertDimensions(800, 600, $result);
    }
}
