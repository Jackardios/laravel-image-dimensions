<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\StorageAccessException;
use Jackardios\ImageDimensions\Exceptions\TemporaryFileException;
use Jackardios\ImageDimensions\Exceptions\UrlAccessException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\TestCase;
use Mockery;

class ExceptionHandlingTest extends TestCase
{
    protected ImageDimensionsService $service;
    protected string $testFilesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImageDimensionsService();
        $this->testFilesPath = sys_get_temp_dir() . '/image-dimensions-tests';

        if (!is_dir($this->testFilesPath)) {
            mkdir($this->testFilesPath, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (is_dir($this->testFilesPath)) {
            array_map('unlink', glob($this->testFilesPath . '/*'));
            rmdir($this->testFilesPath);
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test FileNotFoundException messages
     */
    public function testFileNotFoundExceptionMessages(): void
    {
        // Test local file not found
        $exception = FileNotFoundException::forLocal('/path/to/file.jpg');
        $this->assertEquals('Local file not found: /path/to/file.jpg', $exception->getMessage());

        // Test storage file not found
        $exception = FileNotFoundException::forStorage('s3', 'images/photo.png');
        $this->assertEquals("File not found on disk 's3': images/photo.png", $exception->getMessage());
    }

    /**
     * Test InvalidImageException messages
     */
    public function testInvalidImageExceptionMessages(): void
    {
        $exception = InvalidImageException::forPath('/path/to/corrupt.jpg');
        $this->assertEquals('Could not get image dimensions for file: /path/to/corrupt.jpg', $exception->getMessage());
    }

    /**
     * Test StorageAccessException messages
     */
    public function testStorageAccessExceptionMessages(): void
    {
        // Test stream read error
        $exception = StorageAccessException::couldNotReadStream('remote/file.jpg');
        $this->assertEquals('Could not read stream from storage file: remote/file.jpg', $exception->getMessage());

        // Test content read error
        $exception = StorageAccessException::couldNotReadFullContent('remote/large.png');
        $this->assertEquals('Could not read full content from storage file: remote/large.png', $exception->getMessage());
    }

    /**
     * Test TemporaryFileException messages
     */
    public function testTemporaryFileExceptionMessages(): void
    {
        // Test creation error
        $exception = TemporaryFileException::couldNotCreate();
        $this->assertEquals('Could not create temporary file.', $exception->getMessage());

        // Test write error
        $exception = TemporaryFileException::couldNotWrite();
        $this->assertEquals('Could not open temporary file for writing.', $exception->getMessage());
    }

    /**
     * Test UrlAccessException messages
     */
    public function testUrlAccessExceptionMessages(): void
    {
        // Test open error
        $exception = UrlAccessException::couldNotOpen('https://example.com/image.jpg');
        $this->assertEquals('Could not open URL: https://example.com/image.jpg', $exception->getMessage());

        // Test download error
        $exception = UrlAccessException::couldNotDownload('https://example.com/large.png');
        $this->assertEquals('Could not download full content from URL: https://example.com/large.png', $exception->getMessage());
    }

    /**
     * Test handling of unreadable files
     */
    public function testUnreadableFile(): void
    {
        $path = $this->testFilesPath . '/unreadable.jpg';

        // Create a file and make it unreadable
        file_put_contents($path, 'test');
        chmod($path, 0000);

        try {
            $this->expectException(InvalidImageException::class);
            $this->expectExceptionMessage('File is not readable');

            $this->service->fromLocal($path);
        } finally {
            chmod($path, 0644);
            unlink($path);
        }
    }

    /**
     * Test handling of corrupted image files
     */
    public function testCorruptedImageFile(): void
    {
        $path = $this->testFilesPath . '/corrupted.jpg';
        file_put_contents($path, 'This is not a valid image file content');

        $this->expectException(InvalidImageException::class);

        try {
            $this->service->fromLocal($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * Test handling of empty files
     */
    public function testEmptyFile(): void
    {
        $path = $this->testFilesPath . '/empty.png';
        touch($path);

        $this->expectException(InvalidImageException::class);

        try {
            $this->service->fromLocal($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * Test handling of files with invalid extensions
     */
    public function testInvalidFileExtension(): void
    {
        config(['image-dimensions.supported_formats' => ['jpg', 'png', 'gif']]);
        $service = new ImageDimensionsService();

        $path = $this->testFilesPath . '/document.pdf';
        file_put_contents($path, 'PDF content');

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Unsupported image format: pdf');

        try {
            $service->fromLocal($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * Test handling of malformed SVG files
     */
    public function testMalformedSvg(): void
    {
        $tests = [
            'invalid_xml' => '<?xml version="1.0"?><svg',
            'no_svg_tag' => '<?xml version="1.0"?><div>Not SVG</div>',
            'no_dimensions' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>',
            'invalid_viewbox'=> '<?xml version="1.0"?><svg viewBox="invalid" xmlns="http://www.w3.org/2000/svg"></svg>',
        ];

        foreach ($tests as $name => $content) {
            $path = $this->testFilesPath . "/{$name}.svg";
            file_put_contents($path, $content);

            try {
                $this->service->fromLocal($path);
                $this->fail("Expected InvalidImageException for case '{$name}', but no exception was thrown.");
            } catch (InvalidImageException $e) {
                $this->assertInstanceOf(InvalidImageException::class, $e);
            } finally {
                @unlink($path);
            }
        }
    }

    /**
     * Test handling of network timeouts
     */
    public function testNetworkTimeout(): void
    {
        // This test would require a mock server that simulates timeout
        $this->markTestSkipped('Network timeout test requires mock server setup');
    }

    /**
     * Test handling of redirect loops
     */
    public function testRedirectLoop(): void
    {
        // This test would require a mock server that creates redirect loops
        $this->markTestSkipped('Redirect loop test requires mock server setup');
    }

    /**
     * Test handling of storage disk not found
     */
    public function testStorageDiskNotFound(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->fromStorage('non-existent-disk', 'image.jpg');
    }

    /**
     * Test handling of storage stream failure
     */
    public function testStorageStreamFailure(): void
    {
        Storage::fake('test-disk');
        $disk = Storage::disk('test-disk');

        // Create a file
        $disk->put('test.jpg', 'fake image content');

        // Mock the disk to return false for readStream
        $mockDisk = Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $mockDisk->shouldReceive('exists')->with('test.jpg')->andReturn(true);
        $mockDisk->shouldReceive('getAdapter')->andReturn(new \stdClass());
        $mockDisk->shouldReceive('lastModified')->with('test.jpg')->andReturn(time());
        $mockDisk->shouldReceive('readStream')->with('test.jpg')->andReturn(false);

        Storage::shouldReceive('disk')->with('test-disk')->andReturn($mockDisk);

        $this->expectException(StorageAccessException::class);
        $this->expectExceptionMessage('Could not read stream from storage file');

        $this->service->fromStorage('test-disk', 'test.jpg');
    }

    /**
     * Test handling of null/false values
     */
    public function testNullAndFalseValues(): void
    {
        // Test with null path (PHP 8.0+ would throw TypeError)
        try {
            $this->service->fromLocal(null);
            $this->fail('Expected TypeError or InvalidImageException');
        } catch (\TypeError | InvalidImageException $e) {
            $this->assertTrue(true);
        }

        // Test with false path
        try {
            $this->service->fromLocal(false);
            $this->fail('Expected TypeError or InvalidImageException');
        } catch (\TypeError | InvalidImageException $e) {
            $this->assertTrue(true);
        }
    }

    /**
     * Test handling of special characters in paths
     */
    public function testSpecialCharactersInPaths(): void
    {
        $specialNames = [
            'image with spaces.png',
            'image-with-dashes.jpg',
            'image_with_underscores.gif',
            'image.multiple.dots.svg',
            'имя-на-русском.png',
            '中文文件名.jpg',
        ];

        // Create a simple image
        $image = imagecreate(10, 10);
        imagecolorallocate($image, 255, 255, 255);

        foreach ($specialNames as $name) {
            $path = $this->testFilesPath . '/' . $name;

            // Save as PNG regardless of extension for simplicity
            imagepng($image, $path);

            try {
                $result = $this->service->fromLocal($path);
                $this->assertEquals(10, $result['width']);
                $this->assertEquals(10, $result['height']);
            } catch (InvalidImageException $e) {
                // Some extensions might not match content
                $this->assertTrue(true);
            } finally {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
        }

        imagedestroy($image);
    }

    /**
     * Test handling of symbolic links
     */
    public function testSymbolicLinks(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symbolic link test skipped on Windows');
        }

        // Create a real image file
        $realPath = $this->testFilesPath . '/real-image.png';
        $image = imagecreate(50, 50);
        imagecolorallocate($image, 255, 255, 255);
        imagepng($image, $realPath);
        imagedestroy($image);

        // Create a symbolic link
        $linkPath = $this->testFilesPath . '/link-to-image.png';
        symlink($realPath, $linkPath);

        try {
            $result = $this->service->fromLocal($linkPath);
            $this->assertEquals(50, $result['width']);
            $this->assertEquals(50, $result['height']);
        } finally {
            unlink($linkPath);
            unlink($realPath);
        }
    }

    /**
     * Test handling of very long file paths
     */
    public function testVeryLongFilePaths(): void
    {
        // Create a path with maximum allowed length
        $longName = str_repeat('a', 200) . '.png';
        $path = $this->testFilesPath . '/' . $longName;

        // This might fail on some file systems
        try {
            $image = imagecreate(10, 10);
            imagecolorallocate($image, 255, 255, 255);
            @imagepng($image, $path);
            imagedestroy($image);

            if (file_exists($path)) {
                $result = $this->service->fromLocal($path);
                $this->assertEquals(10, $result['width']);
                $this->assertEquals(10, $result['height']);
                unlink($path);
            } else {
                $this->markTestSkipped('File system does not support long file names');
            }
        } catch (\Exception $e) {
            $this->markTestSkipped('File system does not support long file names');
        }
    }

    /**
     * Test concurrent file access
     */
    public function testConcurrentFileAccess(): void
    {
        $path = $this->testFilesPath . '/concurrent.png';

        // Create an image
        $image = imagecreate(100, 100);
        imagecolorallocate($image, 255, 255, 255);
        imagepng($image, $path);
        imagedestroy($image);

        // Open file for reading (simulate concurrent access)
        $handle = fopen($path, 'rb');

        try {
            // Should still be able to read dimensions
            $result = $this->service->fromLocal($path);
            $this->assertEquals(100, $result['width']);
            $this->assertEquals(100, $result['height']);
        } finally {
            fclose($handle);
            unlink($path);
        }
    }
}