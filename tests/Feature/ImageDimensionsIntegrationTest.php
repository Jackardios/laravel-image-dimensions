<?php

namespace Jackardios\ImageDimensions\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\TemporaryFileException;
use Jackardios\ImageDimensions\Facades\ImageDimensions;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Providers\ImageDimensionsServiceProvider;
use Jackardios\ImageDimensions\Tests\TestCase;
use ReflectionClass;

class ImageDimensionsIntegrationTest extends TestCase
{
    protected string $testFilesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(ImageDimensionsServiceProvider::class);

        $this->testFilesPath = storage_path('app/test-images');
        if (!is_dir($this->testFilesPath)) {
            mkdir($this->testFilesPath, 0777, true);
        }

        $this->createTestImages();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestImages();
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [ImageDimensionsServiceProvider::class];
    }

    protected function createTestImages(): void
    {
        $png = imagecreate(800, 600);
        imagecolorallocate($png, 255, 255, 255);
        imagepng($png, $this->testFilesPath . '/test.png');
        imagedestroy($png);

        $svg = '<?xml version="1.0"?><svg width="1920" height="1080" xmlns="http://www.w3.org/2000/svg"><rect width="1920" height="1080" fill="red"/></svg>';
        file_put_contents($this->testFilesPath . '/test.svg', $svg );
    }

    protected function cleanupTestImages(): void
    {
        $files = glob($this->testFilesPath . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (is_dir($this->testFilesPath)) {
            @rmdir($this->testFilesPath);
        }
        $configPath = config_path('image-dimensions.php');
        if (file_exists($configPath)) {
            unlink($configPath);
        }
    }

    /** @test */
    public function it_registers_the_service_provider_and_facade(): void
    {
        $this->assertInstanceOf(ImageDimensionsService::class, $this->app->make('image-dimensions'));
        $this->assertInstanceOf(ImageDimensionsService::class, ImageDimensions::getFacadeRoot());
    }

    /** @test */
    public function it_correctly_uses_the_facade_to_get_dimensions(): void
    {
        $result = ImageDimensions::fromLocal($this->testFilesPath . '/test.png');

        $this->assertEquals(['width' => 800, 'height' => 600], $result);
    }

    /** @test */
    public function it_publishes_the_configuration_file(): void
    {
        $configPath = config_path('image-dimensions.php');
        $this->assertFileDoesNotExist($configPath);

        Artisan::call('vendor:publish', ['--tag' => 'image-dimensions-config']);

        $this->assertFileExists($configPath);
    }

    /** @test */
    public function it_uses_values_from_the_configuration(): void
    {
        Config::set('image-dimensions.remote_read_bytes', 16384);
        Config::set('image-dimensions.enable_cache', false);

        $service = new ImageDimensionsService();
        $reflection = new ReflectionClass($service);

        $remoteReadBytesProp = $reflection->getProperty('remoteReadBytes');
        $remoteReadBytesProp->setAccessible(true);
        $this->assertEquals(16384, $remoteReadBytesProp->getValue($service));

        $enableCacheProp = $reflection->getProperty('enableCache');
        $enableCacheProp->setAccessible(true);
        $this->assertFalse($enableCacheProp->getValue($service));
    }

    /** @test */
    public function it_gets_dimensions_from_a_local_laravel_storage_disk(): void
    {
        Storage::fake('images');
        Storage::disk('images')->put('test.png', file_get_contents($this->testFilesPath . '/test.png'));

        $result = ImageDimensions::fromStorage('images', 'test.png');

        $this->assertEquals(['width' => 800, 'height' => 600], $result);
    }

    /** @test */
    public function it_gets_dimensions_from_a_remote_laravel_storage_disk(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('images/test.svg', file_get_contents($this->testFilesPath . '/test.svg'));

        $result = ImageDimensions::fromStorage('s3', 'images/test.svg');

        $this->assertEquals(['width' => 1920, 'height' => 1080], $result);
    }

    /** @test */
    public function it_throws_exception_if_temp_directory_is_not_writable(): void
    {
        $unwritableDir = $this->testFilesPath . '/unwritable';
        mkdir($unwritableDir, 0555, true);

        Config::set('image-dimensions.temp_dir', $unwritableDir);
        $service = new ImageDimensionsService();

        $this->expectException(TemporaryFileException::class);
        $this->expectExceptionMessage('Could not create temporary file');

        try {
            Http::fake(['http://example.com/image.png' => Http::response('image data')]);
            $service->fromUrl('http://example.com/image.png' );
        } finally {
            chmod($unwritableDir, 0777);
            rmdir($unwritableDir);
        }
    }

    /** @test */
    public function it_works_with_different_cache_drivers(): void
    {
        $path = $this->testFilesPath . '/test.png';
        $expected = ['width' => 800, 'height' => 600];

        Config::set('cache.default', 'array');
        Cache::flush();
        $this->assertEquals($expected, ImageDimensions::fromLocal($path));
        $this->assertTrue(Cache::has('image_dimensions:local:' . md5(realpath($path)) . ':' . filemtime($path)));

        Config::set('cache.default', 'file');
        Cache::flush();
        $this->assertEquals($expected, ImageDimensions::fromLocal($path));
        $this->assertTrue(Cache::has('image_dimensions:local:' . md5(realpath($path)) . ':' . filemtime($path)));
    }

    /** @test */
    public function it_handles_malformed_svg_files(): void
    {
        $malformedSvg = '<?xml version="1.0"?><svg><rect>';
        $path = $this->testFilesPath . '/malformed.svg';
        file_put_contents($path, $malformedSvg);

        $this->expectException(InvalidImageException::class);
        ImageDimensions::fromLocal($path);
    }

    /** @test */
    public function it_falls_back_to_viewbox_for_svg_with_percentage_dimensions(): void
    {
        $svg = '<svg width="100%" height="100%" viewBox="0 0 200 150"><rect width="100%" height="100%"/></svg>';
        $path = $this->testFilesPath . '/percentage.svg';
        file_put_contents($path, $svg);

        $result = ImageDimensions::fromLocal($path);

        $this->assertEquals(['width' => 200, 'height' => 150], $result);
    }

    /** @test */
    public function it_handles_various_mime_types_and_extensions_correctly(): void
    {
        // Copy the PNG file with the JPG extension
        $sourcePath = $this->testFilesPath . '/test.png';
        $destPath = $this->testFilesPath . '/image.jpg';
        copy($sourcePath, $destPath);

        // The library should determine the size by the content, not by the extension
        $result = ImageDimensions::fromLocal($destPath);
        $this->assertEquals(['width' => 800, 'height' => 600], $result);
    }
}