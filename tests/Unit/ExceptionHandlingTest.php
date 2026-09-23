<?php

namespace Jackardios\ImageDimensions\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\StorageAccessException;
use Jackardios\ImageDimensions\Exceptions\TemporaryFileException;
use Jackardios\ImageDimensions\Exceptions\UrlAccessException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\TestCase;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\Attributes\Test;

class ExceptionHandlingTest extends TestCase
{
    protected ImageDimensionsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ImageDimensionsService;
    }

    #[Test]
    public function it_throws_for_unreadable_local_file(): void
    {
        $path = $this->createImage('unreadable.png', 10, 10);
        $this->makeUnreadable($path);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('File is not readable');
        $this->service->fromLocal($path);
    }

    #[Test]
    public function it_wraps_connection_failures(): void
    {
        $url = 'https://example.com/timeout.jpg';
        Http::fake([$url => fn () => throw new ConnectionException('Timeout was reached')]);

        try {
            $this->service->fromUrl($url);
            $this->fail('A connection failure must be reported as UrlAccessException.');
        } catch (UrlAccessException $e) {
            $this->assertSame("Could not open URL: {$url}", $e->getMessage());
            $this->assertInstanceOf(ConnectionException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function it_throws_for_too_many_redirects(): void
    {
        $url = 'https://example.com/redirect-loop';
        Http::fake([$url => Http::response(null, 302, ['Location' => $url])]);

        $this->expectException(UrlAccessException::class);
        $this->service->fromUrl($url);
    }

    #[Test]
    public function it_downloads_the_whole_url_when_the_partial_read_is_not_enough(): void
    {
        config(['image-dimensions.remote_read_bytes' => 8192]);
        $service = new ImageDimensionsService;
        $url = 'https://example.com/late-header.jpg';
        $jpeg = $this->jpegWithLeadingComment(40, 30, 20000);
        // A closure yields a fresh body per request; a shared response would
        // already be detached by the first, partial read.
        Http::fake([$url => fn () => Http::response($jpeg)]);

        $this->assertSame(['width' => 40, 'height' => 30], $service->fromUrl($url));
        // 1.x issues a second, full request when the first 8 KB are not enough.
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_throws_if_storage_stream_cannot_be_read(): void
    {
        $this->useInMemoryDisk('remote', new class extends InMemoryFilesystemAdapter
        {
            public function readStream(string $path)
            {
                throw UnableToReadFile::fromLocation($path, 'simulated failure');
            }
        })->put('image.png', 'irrelevant');

        $this->expectException(StorageAccessException::class);
        $this->expectExceptionMessage('Could not read stream from storage file: image.png');
        $this->service->fromStorage('remote', 'image.png');
    }

    #[Test]
    public function it_throws_if_storage_full_content_cannot_be_read_after_partial_failure(): void
    {
        $this->useInMemoryDisk('remote', new class extends InMemoryFilesystemAdapter
        {
            public function read(string $path): string
            {
                throw UnableToReadFile::fromLocation($path, 'simulated failure');
            }
        })->put('image.png', 'not an image');

        $this->expectException(StorageAccessException::class);
        $this->expectExceptionMessage('Could not read full content from storage file: image.png');
        $this->service->fromStorage('remote', 'image.png');
    }

    #[Test]
    public function it_reads_the_whole_storage_file_when_the_partial_read_is_not_enough(): void
    {
        config(['image-dimensions.remote_read_bytes' => 8192]);
        $service = new ImageDimensionsService;
        $this->useInMemoryDisk('remote')->put('late-header.jpg', $this->jpegWithLeadingComment(40, 30, 20000));

        $this->assertSame(['width' => 40, 'height' => 30], $service->fromStorage('remote', 'late-header.jpg'));
    }

    #[Test]
    public function it_throws_if_temp_dir_does_not_exist_when_processing_url(): void
    {
        config(['image-dimensions.temp_dir' => $this->tempPath.DIRECTORY_SEPARATOR.'missing']);
        $service = new ImageDimensionsService;
        Http::fake();

        $this->expectException(TemporaryFileException::class);
        $this->expectExceptionMessage('Could not create temporary file');
        $service->fromUrl('https://example.com/image.png');
    }

    #[Test]
    public function it_throws_if_temp_dir_does_not_exist_when_processing_storage(): void
    {
        config(['image-dimensions.temp_dir' => $this->tempPath.DIRECTORY_SEPARATOR.'missing']);
        $service = new ImageDimensionsService;
        $this->useInMemoryDisk('remote')->put('image.png', 'irrelevant');

        $this->expectException(TemporaryFileException::class);
        $this->expectExceptionMessage('Could not create temporary file');
        $service->fromStorage('remote', 'image.png');
    }

    #[Test]
    public function it_removes_temporary_files_on_success_and_failure(): void
    {
        $tempDir = $this->tempPath.DIRECTORY_SEPARATOR.'tmp';
        mkdir($tempDir);
        config(['image-dimensions.temp_dir' => $tempDir, 'image-dimensions.enable_cache' => false]);
        $service = new ImageDimensionsService;
        $png = file_get_contents($this->createImage('test.png', 10, 20));
        Http::fake([
            'example.com/ok.png' => fn () => Http::response($png),
            'example.com/bad.png' => fn () => Http::response('not an image'),
        ]);
        $disk = $this->useInMemoryDisk('remote');
        $disk->put('ok.png', $png);
        $disk->put('bad.png', 'not an image');

        $this->assertSame(['width' => 10, 'height' => 20], $service->fromUrl('https://example.com/ok.png'));
        $this->assertSame(['width' => 10, 'height' => 20], $service->fromStorage('remote', 'ok.png'));

        foreach ([fn () => $service->fromUrl('https://example.com/bad.png'), fn () => $service->fromStorage('remote', 'bad.png')] as $call) {
            try {
                $call();
                $this->fail('Invalid image data must be rejected.');
            } catch (UrlAccessException|InvalidImageException) {
            }
        }

        $this->assertSame([], array_values(array_diff(scandir($tempDir), ['.', '..'])));
    }

    /**
     * A JPEG whose SOF marker sits behind a comment segment of $padding
     * bytes, so a truncated read cannot see the dimensions.
     */
    private function jpegWithLeadingComment(int $width, int $height, int $padding): string
    {
        $jpeg = file_get_contents($this->createImage('source.jpg', $width, $height, 'jpg'));
        $segments = '';

        for ($left = $padding; $left > 0; $left -= $chunk) {
            $chunk = min($left, 65533);
            $segments .= "\xFF\xFE".pack('n', $chunk + 2).str_repeat('x', $chunk);
        }

        return substr($jpeg, 0, 2).$segments.substr($jpeg, 2);
    }
}
