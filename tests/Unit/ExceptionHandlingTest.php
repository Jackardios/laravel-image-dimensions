<?php

declare(strict_types=1);

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
use PHPUnit\Framework\Attributes\Test;

class ExceptionHandlingTest extends TestCase
{
    protected ImageDimensionsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImageDimensionsService;
    }

    // --- Local File Exceptions ---

    #[Test]
    public function it_throws_for_non_existent_local_file(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->service->fromLocal($this->tempPath.'/non-existent.jpg');
    }

    #[Test]
    public function it_throws_for_unreadable_local_file(): void
    {
        $path = $this->createImage('unreadable.jpg', 10, 10, 'jpg');
        $this->makeUnreadable($path);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('File is not readable');
        $this->service->fromLocal($path);
    }

    #[Test]
    public function it_throws_for_corrupted_image_file(): void
    {
        $path = $this->createFile('corrupted.jpg', 'this is not a valid image');

        $this->expectException(InvalidImageException::class);
        $this->service->fromLocal($path);
    }

    #[Test]
    public function it_throws_for_empty_local_file(): void
    {
        $path = $this->createFile('empty.png', '');

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('File is empty');
        $this->service->fromLocal($path);
    }

    // --- URL Exceptions ---

    #[Test]
    public function it_throws_for_invalid_url_format(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Invalid URL provided');
        $this->service->fromUrl('not-a-valid-url');
    }

    #[Test]
    public function it_throws_for_http_request_failure(): void
    {
        $url = 'https://example.com/not-found.jpg';
        Http::fake([$url => Http::response(null, 404)]);

        $this->expectException(UrlAccessException::class);
        $this->expectExceptionMessage("Could not open URL: {$url}");
        $this->service->fromUrl($url);
    }

    #[Test]
    public function it_throws_for_network_connection_timeout(): void
    {
        $url = 'https://example.com/timeout.jpg';
        Http::fake([
            $url => fn () => throw new ConnectionException('Timeout was reached'),
        ]);

        $this->expectException(UrlAccessException::class);
        $this->expectExceptionMessage("Could not open URL: {$url}");
        $this->service->fromUrl($url);
    }

    #[Test]
    public function it_throws_for_too_many_redirects(): void
    {
        $url = 'https://example.com/redirect-loop';
        Http::fake([$url => Http::response(null, 302, ['Location' => $url])]);

        $this->expectException(UrlAccessException::class);
        $this->service->fromUrl($url);
    }

    // --- Storage Exceptions ---

    #[Test]
    public function it_throws_for_non_existent_storage_disk(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage("Storage disk 'non-existent-disk' does not exist");
        $this->service->fromStorage('non-existent-disk', 'image.jpg');
    }

    #[Test]
    public function it_throws_for_non_existent_file_on_storage_disk(): void
    {
        Storage::fake('test-disk');
        $this->expectException(FileNotFoundException::class);
        $this->service->fromStorage('test-disk', 'non-existent.jpg');
    }

    #[Test]
    public function it_throws_if_storage_stream_cannot_be_read(): void
    {
        $diskName = 's3_mock';
        $path = 'image.png';

        $mockDisk = Mockery::mock(Storage::getFacadeRoot());
        $mockDisk->shouldReceive('exists')->with($path)->andReturn(true);
        $mockDisk->shouldReceive('getAdapter')->andReturn(new \stdClass);
        $mockDisk->shouldReceive('lastModified')->with($path)->andReturn(time());
        $mockDisk->shouldReceive('readStream')->with($path)->andReturn(false);

        Storage::shouldReceive('disk')->with($diskName)->andReturn($mockDisk);

        $this->expectException(StorageAccessException::class);
        $this->expectExceptionMessage("Could not read stream from storage file: {$path}");
        $this->service->fromStorage($diskName, $path);
    }

    #[Test]
    public function it_throws_invalid_image_when_a_storage_stream_is_not_an_image(): void
    {
        // A fully-readable but non-image stream that reaches EOF should surface
        // an InvalidImageException (the stream is drained in place; there is no
        // second "read full content" round-trip).
        $diskName = 's3_mock';
        $path = 'image.png';

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'invalid stream data');
        rewind($stream);

        Storage::shouldReceive('disk')->with($diskName)->andReturnSelf();
        Storage::shouldReceive('exists')->with($path)->andReturn(true);
        Storage::shouldReceive('getAdapter')->andReturn(new \stdClass);
        Storage::shouldReceive('lastModified')->with($path)->andReturn(time());
        Storage::shouldReceive('readStream')->with($path)->andReturn($stream);

        $this->expectException(InvalidImageException::class);
        $this->service->fromStorage($diskName, $path);
    }

    // --- Configuration Exceptions ---

    #[Test]
    public function it_throws_if_temp_dir_is_not_writable_when_processing_url(): void
    {
        $url = 'https://example.com/image.png';
        Http::fake([$url => Http::response('image data', 200)]);

        Config::set('image-dimensions.temp_dir', $this->createReadOnlyDirectory('unwritable'));
        $serviceWithBadConfig = new ImageDimensionsService;

        $this->expectException(TemporaryFileException::class);
        $this->expectExceptionMessage('Could not create temporary file');
        $serviceWithBadConfig->fromUrl($url);
    }
}
