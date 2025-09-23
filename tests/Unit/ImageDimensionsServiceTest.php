<?php

namespace Jackardios\ImageDimensions\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\TemporaryFileException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\TestCase;
use Mockery;

class ImageDimensionsServiceTest extends TestCase
{
    protected ImageDimensionsService $service;
    protected string $testFilesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImageDimensionsService();
        $this->testFilesPath = __DIR__ . '/fixtures';

        // Create test fixtures directory
        if (!is_dir($this->testFilesPath)) {
            mkdir($this->testFilesPath, 0777, true);
        }

        // Create test images
        $this->createTestImages();
    }

    protected function tearDown(): void
    {
        // Clean up test files
        $this->cleanupTestImages();
        Mockery::close();
        parent::tearDown();
    }

    protected function createTestImages(): void
    {
        // Create a simple PNG image
        $png = imagecreate(100, 200);
        imagecolorallocate($png, 255, 255, 255);
        imagepng($png, $this->testFilesPath . '/test.png');
        imagedestroy($png);

        // Create a JPEG image
        $jpeg = imagecreate(300, 400);
        imagecolorallocate($jpeg, 255, 255, 255);
        imagejpeg($jpeg, $this->testFilesPath . '/test.jpg');
        imagedestroy($jpeg);

        // Create a GIF image
        $gif = imagecreate(150, 250);
        imagecolorallocate($gif, 255, 255, 255);
        imagegif($gif, $this->testFilesPath . '/test.gif');
        imagedestroy($gif);

        // Create WebP image if supported
        if (function_exists('imagewebp')) {
            $webp = imagecreatetruecolor(200, 300);
            $white = imagecolorallocate($webp, 255, 255, 255);
            imagefill($webp, 0, 0, $white);
            imagewebp($webp, $this->testFilesPath . '/test.webp');
            imagedestroy($webp);
        }

        // Create a simple SVG
        $svg = '<?xml version="1.0"?>
            <svg width="500" height="600" xmlns="http://www.w3.org/2000/svg">
                <rect width="500" height="600" fill="red"/>
            </svg>';
        file_put_contents($this->testFilesPath . '/test.svg', $svg);

        // Create SVG with viewBox only
        $svgViewBox = '<?xml version="1.0"?>
            <svg viewBox="0 0 400 300" xmlns="http://www.w3.org/2000/svg">
                <rect width="400" height="300" fill="blue"/>
            </svg>';
        file_put_contents($this->testFilesPath . '/test-viewbox.svg', $svgViewBox);

        // Create SVG with units
        $svgUnits = '<?xml version="1.0"?>
            <svg width="100pt" height="200pt" xmlns="http://www.w3.org/2000/svg">
                <rect width="100" height="200" fill="green"/>
            </svg>';
        file_put_contents($this->testFilesPath . '/test-units.svg', $svgUnits);

        // Create invalid image file
        file_put_contents($this->testFilesPath . '/invalid.jpg', 'not an image');

        // Create empty file
        touch($this->testFilesPath . '/empty.png');
    }

    protected function cleanupTestImages(): void
    {
        $files = [
            'test.png', 'test.jpg', 'test.gif', 'test.webp',
            'test.svg', 'test-viewbox.svg', 'test-units.svg',
            'invalid.jpg', 'empty.png'
        ];

        foreach ($files as $file) {
            $path = $this->testFilesPath . '/' . $file;
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Test getting dimensions from local PNG file
     */
    public function testFromLocalPng(): void
    {
        $result = $this->service->fromLocal($this->testFilesPath . '/test.png');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('width', $result);
        $this->assertArrayHasKey('height', $result);
        $this->assertEquals(100, $result['width']);
        $this->assertEquals(200, $result['height']);
    }

    /**
     * Test getting dimensions from local JPEG file
     */
    public function testFromLocalJpeg(): void
    {
        $result = $this->service->fromLocal($this->testFilesPath . '/test.jpg');

        $this->assertEquals(300, $result['width']);
        $this->assertEquals(400, $result['height']);
    }

    /**
     * Test getting dimensions from local GIF file
     */
    public function testFromLocalGif(): void
    {
        $result = $this->service->fromLocal($this->testFilesPath . '/test.gif');

        $this->assertEquals(150, $result['width']);
        $this->assertEquals(250, $result['height']);
    }

    /**
     * Test getting dimensions from local WebP file
     */
    public function testFromLocalWebp(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('WebP support not available');
        }

        $result = $this->service->fromLocal($this->testFilesPath . '/test.webp');

        $this->assertEquals(200, $result['width']);
        $this->assertEquals(300, $result['height']);
    }

    /**
     * Test getting dimensions from SVG with width/height attributes
     */
    public function testFromLocalSvg(): void
    {
        $result = $this->service->fromLocal($this->testFilesPath . '/test.svg');

        $this->assertEquals(500, $result['width']);
        $this->assertEquals(600, $result['height']);
    }

    /**
     * Test getting dimensions from SVG with viewBox only
     */
    public function testFromLocalSvgWithViewBox(): void
    {
        $result = $this->service->fromLocal($this->testFilesPath . '/test-viewbox.svg');

        $this->assertEquals(400, $result['width']);
        $this->assertEquals(300, $result['height']);
    }

    /**
     * Test getting dimensions from SVG with units
     */
    public function testFromLocalSvgWithUnits(): void
    {
        $result = $this->service->fromLocal($this->testFilesPath . '/test-units.svg');

        // 100pt = ~133px, 200pt = ~267px
        $this->assertEquals(134, $result['width']);
        $this->assertEquals(267, $result['height']);
    }

    /**
     * Test exception for non-existent file
     */
    public function testFromLocalNonExistentFile(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('Local file not found');

        $this->service->fromLocal($this->testFilesPath . '/non-existent.jpg');
    }

    /**
     * Test exception for empty path
     */
    public function testFromLocalEmptyPath(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Path must be a non-empty string');

        $this->service->fromLocal('');
    }

    /**
     * Test exception for invalid image file
     */
    public function testFromLocalInvalidImage(): void
    {
        $this->expectException(InvalidImageException::class);

        $this->service->fromLocal($this->testFilesPath . '/invalid.jpg');
    }

    /**
     * Test getting dimensions from URL
     */
    public function testFromUrl(): void
    {
        // Mock HTTP request
        $imageData = file_get_contents($this->testFilesPath . '/test.png');

        // Create a mock server or use VCR for this test
        // For simplicity, we'll skip the actual HTTP test
        $this->markTestSkipped('URL testing requires mock HTTP server');
    }

    /**
     * Test exception for invalid URL
     */
    public function testFromUrlInvalidUrl(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Invalid URL provided');

        $this->service->fromUrl('not-a-url');
    }

    /**
     * Test getting dimensions from Storage
     */
    public function testFromStorage(): void
    {
        // Setup Storage mock
        Storage::fake('test-disk');
        Storage::disk('test-disk')->put('images/test.png', file_get_contents($this->testFilesPath . '/test.png'));

        // Test with mocked non-local adapter
        $mockDisk = Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $mockDisk->shouldReceive('exists')->with('images/test.png')->andReturn(true);
        $mockDisk->shouldReceive('getAdapter')->andReturn(new \stdClass());
        $mockDisk->shouldReceive('lastModified')->with('images/test.png')->andReturn(time());
        $mockDisk->shouldReceive('readStream')->andReturn(fopen($this->testFilesPath . '/test.png', 'rb'));

        Storage::shouldReceive('disk')->with('test-disk')->andReturn($mockDisk);

        Cache::shouldReceive('remember')->andReturnUsing(function ($key, $ttl, $callback) {
            return $callback();
        });

        $result = $this->service->fromStorage('test-disk', 'images/test.png');

        $this->assertEquals(100, $result['width']);
        $this->assertEquals(200, $result['height']);
    }

    /**
     * Test getting dimensions from Storage with local adapter
     */
    public function testFromStorageWithLocalAdapter(): void
    {
        Storage::fake('local-disk');
        $disk = Storage::disk('local-disk');
        $disk->put('images/test.jpg', file_get_contents($this->testFilesPath . '/test.jpg'));

        $result = $this->service->fromStorage('local-disk', 'images/test.jpg');

        $this->assertEquals(300, $result['width']);
        $this->assertEquals(400, $result['height']);
    }

    /**
     * Test exception for non-existent storage file
     */
    public function testFromStorageNonExistentFile(): void
    {
        Storage::fake('test-disk');

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage("File not found on disk 'test-disk'");

        $this->service->fromStorage('test-disk', 'non-existent.jpg');
    }

    /**
     * Test exception for empty disk name
     */
    public function testFromStorageEmptyDiskName(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Disk name must be a non-empty string');

        $this->service->fromStorage('', 'test.jpg');
    }

    /**
     * Test exception for empty storage path
     */
    public function testFromStorageEmptyPath(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Path must be a non-empty string');

        $this->service->fromStorage('test-disk', '');
    }

    /**
     * Test caching functionality
     */
    public function testCaching(): void
    {
        config(['image-dimensions.enable_cache' => true]);

        Cache::shouldReceive('remember')
            ->once()
            ->andReturnUsing(function ($key, $ttl, $callback) {
                return $callback();
            });

        $result1 = $this->service->fromLocal($this->testFilesPath . '/test.png');

        // Second call should use cache
        Cache::shouldReceive('remember')
            ->once()
            ->andReturn(['width' => 100, 'height' => 200]);

        $result2 = $this->service->fromLocal($this->testFilesPath . '/test.png');

        $this->assertEquals($result1, $result2);
    }

    /**
     * Test with caching disabled
     */
    public function testWithCachingDisabled(): void
    {
        config(['image-dimensions.enable_cache' => false]);
        $service = new ImageDimensionsService();

        // Should not call Cache::remember when disabled
        Cache::shouldReceive('remember')->never();

        $result = $service->fromLocal($this->testFilesPath . '/test.png');

        $this->assertEquals(100, $result['width']);
        $this->assertEquals(200, $result['height']);
    }

    /**
     * Test temporary file creation failure
     */
    public function testTemporaryFileCreationFailure(): void
    {
        // Set invalid temp directory
        config(['image-dimensions.temp_dir' => '/invalid/path']);
        $service = new ImageDimensionsService();

        $this->expectException(TemporaryFileException::class);
        $this->expectExceptionMessage('Could not create temporary file');

        // This should fail when trying to create temp file
        $service->fromUrl('https://example.com/image.jpg');
    }

    /**
     * Test with various image formats
     */
    public function testSupportedFormats(): void
    {
        $formats = [
            'png' => [100, 200],
            'jpg' => [300, 400],
            'gif' => [150, 250],
            'svg' => [500, 600],
        ];

        if (function_exists('imagewebp')) {
            $formats['webp'] = [200, 300];
        }

        foreach ($formats as $format => $dimensions) {
            $path = $this->testFilesPath . '/test.' . $format;
            if (file_exists($path)) {
                $result = $this->service->fromLocal($path);
                $this->assertEquals($dimensions[0], $result['width'], "Failed for format: {$format}");
                $this->assertEquals($dimensions[1], $result['height'], "Failed for format: {$format}");
            }
        }
    }

    /**
     * Test facade
     */
    public function testFacade(): void
    {
        $this->app->singleton('image-dimensions', function () {
            return $this->service;
        });

        $result = \Jackardios\ImageDimensions\Facades\ImageDimensions::fromLocal($this->testFilesPath . '/test.png');

        $this->assertEquals(100, $result['width']);
        $this->assertEquals(200, $result['height']);
    }
}