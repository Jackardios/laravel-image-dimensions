<?php

namespace Jackardios\ImageDimensions\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
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
        $this->testFilesPath = sys_get_temp_dir() . '/image-dimensions-tests-' . uniqid();

        if (!is_dir($this->testFilesPath)) {
            mkdir($this->testFilesPath, 0777, true);
        }

        $img = imagecreate(10, 10);
        imagecolorallocate($img, 255, 255, 255);
        imagepng($img, $this->testFilesPath . '/image.png');
        imagedestroy($img);
    }

    protected function tearDown(): void
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
        Mockery::close();
        parent::tearDown();
    }

    // --- Local File Exceptions ---

    /** @test */
    public function it_throws_for_non_existent_local_file(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->service->fromLocal($this->testFilesPath . '/non-existent.jpg');
    }

    /** @test */
    public function it_throws_for_unreadable_local_file(): void
    {
        $path = $this->testFilesPath . '/unreadable.jpg';
        touch($path);
        chmod($path, 0000); // Make the file unreadable

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('File is not readable');

        try {
            $this->service->fromLocal($path);
        } finally {
            chmod($path, 0644); // Restore permissions for proper cleaning
        }
    }

    /** @test */
    public function it_throws_for_corrupted_image_file(): void
    {
        $path = $this->testFilesPath . '/corrupted.jpg';
        file_put_contents($path, 'this is not a valid image');

        $this->expectException(InvalidImageException::class);
        $this->service->fromLocal($path);
    }

    /** @test */
    public function it_throws_for_empty_local_file(): void
    {
        $path = $this->testFilesPath . '/empty.png';
        touch($path);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('File is empty');
        $this->service->fromLocal($path);
    }

    // --- URL Exceptions ---

    /** @test */
    public function it_throws_for_invalid_url_format(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Invalid URL provided');
        $this->service->fromUrl('not-a-valid-url');
    }

    /** @test */
    public function it_throws_for_http_request_failure( ): void
    {
        $url = 'https://example.com/not-found.jpg';
        Http::fake([$url => Http::response(null, 404 )]);

        $this->expectException(UrlAccessException::class);
        $this->expectExceptionMessage("Could not open URL: {$url}");
        $this->service->fromUrl($url);
    }

    /** @test */
    public function it_throws_for_network_connection_timeout(): void
    {
        $url = 'https://example.com/timeout.jpg';
        Http::fake([
            $url => fn( ) => throw new ConnectionException('Timeout was reached'),
        ]);

        $this->expectException(UrlAccessException::class);
        $this->expectExceptionMessage("Could not open URL: {$url}");
        $this->service->fromUrl($url);
    }

    /** @test */
    public function it_throws_for_too_many_redirects(): void
    {
        $url = 'https://example.com/redirect-loop';
        Http::fake([$url => Http::response(null, 302, ['Location' => $url] )]);

        $this->expectException(UrlAccessException::class);
        $this->service->fromUrl($url);
    }

    // --- Storage Exceptions ---

    /** @test */
    public function it_throws_for_non_existent_storage_disk(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage("Storage disk 'non-existent-disk' does not exist");
        $this->service->fromStorage('non-existent-disk', 'image.jpg');
    }

    /** @test */
    public function it_throws_for_non_existent_file_on_storage_disk(): void
    {
        Storage::fake('test-disk');
        $this->expectException(FileNotFoundException::class);
        $this->service->fromStorage('test-disk', 'non-existent.jpg');
    }

    /** @test */
    public function it_throws_if_storage_stream_cannot_be_read(): void
    {
        $diskName = 's3_mock';
        $path = 'image.png';

        $mockDisk = Mockery::mock(Storage::getFacadeRoot());
        $mockDisk->shouldReceive('exists')->with($path)->andReturn(true);
        $mockDisk->shouldReceive('getAdapter')->andReturn(new \stdClass());
        $mockDisk->shouldReceive('lastModified')->with($path)->andReturn(time());
        $mockDisk->shouldReceive('readStream')->with($path)->andReturn(false);

        Storage::shouldReceive('disk')->with($diskName)->andReturn($mockDisk);

        $this->expectException(StorageAccessException::class);
        $this->expectExceptionMessage("Could not read stream from storage file: {$path}");
        $this->service->fromStorage($diskName, $path);
    }

    /** @test */
    public function it_throws_if_storage_full_content_cannot_be_read_after_partial_failure(): void
    {

        $diskName = 's3_mock';
        $path = 'image.png';

        // A stream with invalid data to cause an InvalidImageException
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'invalid stream data');
        rewind($stream);

        Storage::shouldReceive('disk')->with($diskName)->andReturnSelf();
        Storage::shouldReceive('exists')->with($path)->andReturn(true);
        Storage::shouldReceive('getAdapter')->andReturn(new \stdClass());
        Storage::shouldReceive('lastModified')->with($path)->andReturn(time());
        Storage::shouldReceive('readStream')->with($path)->andReturn($stream);
        Storage::shouldReceive('get')->with($path)->andReturn(null);

        $this->expectException(StorageAccessException::class);
        $this->expectExceptionMessage("Could not read full content from storage file: {$path}");
        $this->service->fromStorage($diskName, $path);
    }

    // --- Configuration Exceptions ---

    /** @test */
    public function it_throws_if_temp_dir_is_not_writable_when_processing_url(): void
    {
        $invalidDir = $this->testFilesPath . '/unwritable';
        mkdir($invalidDir, 0444, true); // Read-only

        Config::set('image-dimensions.temp_dir', $invalidDir);
        $serviceWithBadConfig = new ImageDimensionsService();

        $this->expectException(TemporaryFileException::class);
        $this->expectExceptionMessage('Could not create temporary file');

        try {
            $serviceWithBadConfig->fromUrl('https://example.com/image.png');
        } finally {
            chmod($invalidDir, 0777);
            rmdir($invalidDir);
        }
    }
}