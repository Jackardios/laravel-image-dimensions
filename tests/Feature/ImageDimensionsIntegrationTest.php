<?php

namespace Jackardios\ImageDimensions\Tests\Feature;;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Jackardios\ImageDimensions\Facades\ImageDimensions;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Providers\ImageDimensionsServiceProvider;
use Jackardios\ImageDimensions\Tests\TestCase;

class ImageDimensionsIntegrationTest extends TestCase
{
    protected string $testFilesPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Register service provider
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

    protected function createTestImages(): void
    {
        // Create test images
        $png = imagecreate(800, 600);
        imagecolorallocate($png, 255, 255, 255);
        imagepng($png, $this->testFilesPath . '/large.png');
        imagedestroy($png);

        // Create SVG
        $svg = '<?xml version="1.0"?>
            <svg width="1920" height="1080" xmlns="http://www.w3.org/2000/svg">
                <rect width="1920" height="1080" fill="red"/>
            </svg>';
        file_put_contents($this->testFilesPath . '/banner.svg', $svg);
    }

    protected function cleanupTestImages(): void
    {
        $files = ['large.png', 'banner.svg'];
        foreach ($files as $file) {
            $path = $this->testFilesPath . '/' . $file;
            if (file_exists($path)) {
                unlink($path);
            }
        }

        if (is_dir($this->testFilesPath)) {
            rmdir($this->testFilesPath);
        }
    }

    /**
     * Test service provider registration
     */
    public function testServiceProviderRegistration(): void
    {
        $this->assertInstanceOf(
            ImageDimensionsService::class,
            $this->app->make('image-dimensions')
        );
    }

    /**
     * Test facade works correctly
     */
    public function testFacadeWorks(): void
    {
        $result = ImageDimensions::fromLocal($this->testFilesPath . '/large.png');

        $this->assertEquals(800, $result['width']);
        $this->assertEquals(600, $result['height']);
    }

    /**
     * Test configuration publishing
     */
    public function testConfigurationPublishing(): void
    {
        Artisan::call('vendor:publish', [
            '--tag' => 'image-dimensions-config',
        ]);

        $this->assertFileExists(config_path('image-dimensions.php'));

        // Clean up
        if (file_exists(config_path('image-dimensions.php'))) {
            unlink(config_path('image-dimensions.php'));
        }
    }

    /**
     * Test configuration values are used
     */
    public function testConfigurationValuesAreUsed(): void
    {
        Config::set('image-dimensions.remote_read_bytes', 1024);
        Config::set('image-dimensions.enable_cache', false);

        $service = new ImageDimensionsService();

        // Test that service uses config values
        $reflection = new \ReflectionClass($service);

        $remoteReadBytes = $reflection->getProperty('remoteReadBytes');
        $remoteReadBytes->setAccessible(true);
        $this->assertEquals(1024, $remoteReadBytes->getValue($service));

        $enableCache = $reflection->getProperty('enableCache');
        $enableCache->setAccessible(true);
        $this->assertFalse($enableCache->getValue($service));
    }

    /**
     * Test with Laravel Storage disks
     */
    public function testWithLaravelStorageDisks(): void
    {
        // Create local disk
        Storage::fake('images');
        Storage::disk('images')->put('test.png', file_get_contents($this->testFilesPath . '/large.png'));

        $result = ImageDimensions::fromStorage('images', 'test.png');

        $this->assertEquals(800, $result['width']);
        $this->assertEquals(600, $result['height']);
    }

    /**
     * Test with S3 disk simulation
     */
    public function testWithS3DiskSimulation(): void
    {
        // Create a mock S3 disk
        Storage::fake('s3');
        $disk = Storage::disk('s3');

        // Upload test file
        $disk->put('images/test.svg', file_get_contents($this->testFilesPath . '/banner.svg'));

        $result = ImageDimensions::fromStorage('s3', 'images/test.svg');

        $this->assertEquals(1920, $result['width']);
        $this->assertEquals(1080, $result['height']);
    }

    /**
     * Test error handling with invalid configurations
     */
    public function testErrorHandlingWithInvalidConfig(): void
    {
        Config::set('image-dimensions.temp_dir', '/invalid/directory');

        $service = new ImageDimensionsService();

        $this->expectException(\Jackardios\ImageDimensions\Exceptions\TemporaryFileException::class);

        // This should fail due to invalid temp directory
        Storage::fake('test');
        Storage::disk('test')->put('image.png', file_get_contents($this->testFilesPath . '/large.png'));

        // Force non-local adapter
        $disk = Storage::disk('test');
        $reflection = new \ReflectionClass($disk);
        $adapter = $reflection->getProperty('adapter');
        $adapter->setAccessible(true);
        $adapter->setValue($disk, new \League\Flysystem\InMemory\InMemoryFilesystemAdapter());

        $service->fromStorage('test', 'image.png');
    }

    /**
     * Test with different cache drivers
     */
    public function testWithDifferentCacheDrivers(): void
    {
        // Test with array cache
        Config::set('cache.default', 'array');

        $result1 = ImageDimensions::fromLocal($this->testFilesPath . '/large.png');
        $result2 = ImageDimensions::fromLocal($this->testFilesPath . '/large.png');

        $this->assertEquals($result1, $result2);

        // Test with file cache
        Config::set('cache.default', 'file');

        $result3 = ImageDimensions::fromLocal($this->testFilesPath . '/banner.svg');
        $result4 = ImageDimensions::fromLocal($this->testFilesPath . '/banner.svg');

        $this->assertEquals($result3, $result4);
    }

    /**
     * Test handling of malformed SVG files
     */
    public function testMalformedSvgHandling(): void
    {
        // Create malformed SVG
        $malformedSvg = '<?xml version="1.0"?><svg><rect/>';
        $path = $this->testFilesPath . '/malformed.svg';
        file_put_contents($path, $malformedSvg);

        $this->expectException(\Jackardios\ImageDimensions\Exceptions\InvalidImageException::class);

        try {
            ImageDimensions::fromLocal($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * Test with percentage-based SVG dimensions
     */
    public function testPercentageBasedSvgDimensions(): void
    {
        $svg = '<?xml version="1.0"?>
            <svg width="100%" height="100%" viewBox="0 0 200 150" xmlns="http://www.w3.org/2000/svg">
                <rect width="200" height="150" fill="blue"/>
            </svg>';

        $path = $this->testFilesPath . '/percentage.svg';
        file_put_contents($path, $svg);

        try {
            $result = ImageDimensions::fromLocal($path);

            // Should fall back to viewBox
            $this->assertEquals(200, $result['width']);
            $this->assertEquals(150, $result['height']);
        } finally {
            unlink($path);
        }
    }

    /**
     * Test concurrent access to the same image
     */
    public function testConcurrentAccess(): void
    {
        $path = $this->testFilesPath . '/large.png';

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = ImageDimensions::fromLocal($path);
        }

        // All results should be identical
        foreach ($results as $result) {
            $this->assertEquals(800, $result['width']);
            $this->assertEquals(600, $result['height']);
        }
    }

    /**
     * Test memory usage with large files
     */
    public function testMemoryUsageWithLargeFiles(): void
    {
        // Create a larger image
        $large = imagecreate(2000, 2000);
        imagecolorallocate($large, 255, 255, 255);
        imagepng($large, $this->testFilesPath . '/very-large.png');
        imagedestroy($large);

        $memoryBefore = memory_get_usage();

        $result = ImageDimensions::fromLocal($this->testFilesPath . '/very-large.png');

        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;

        // Memory usage should be reasonable (less than 10MB for dimension reading)
        $this->assertLessThan(10 * 1024 * 1024, $memoryUsed);

        $this->assertEquals(2000, $result['width']);
        $this->assertEquals(2000, $result['height']);

        unlink($this->testFilesPath . '/very-large.png');
    }

    /**
     * Test with various MIME types
     */
    public function testVariousMimeTypes(): void
    {
        // Test with different file extensions and content
        $tests = [
            'image.jpeg' => 'jpg',
            'photo.PNG' => 'png',
            'graphic.SVG' => 'svg',
        ];

        foreach ($tests as $filename => $sourceExt) {
            $sourcePath = $this->testFilesPath . '/' . ($sourceExt === 'svg' ? 'banner.svg' : 'large.png');
            $destPath = $this->testFilesPath . '/' . $filename;

            if (file_exists($sourcePath)) {
                copy($sourcePath, $destPath);

                try {
                    $result = ImageDimensions::fromLocal($destPath);
                    $this->assertArrayHasKey('width', $result);
                    $this->assertArrayHasKey('height', $result);
                } finally {
                    unlink($destPath);
                }
            }
        }
    }
}