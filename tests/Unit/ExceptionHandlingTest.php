<?php

namespace Jackardios\ImageDimensions\Tests\Unit;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

        $img = imagecreate(10, 10);
        imagecolorallocate($img, 255, 255, 255);
        imagepng($img, $this->testFilesPath . '/image.png');
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testFilesPath)) {
            $files = glob($this->testFilesPath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->testFilesPath);
        }
        Mockery::close();
        parent::tearDown();
    }

    // --- LOCAL ---

    /** @test */
    public function it_throws_for_unreadable_local_file(): void
    {
        $path = $this->testFilesPath . '/unreadable.jpg';
        touch($path);
        chmod($path, 0000);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('File is not readable');
        try {
            $this->service->fromLocal($path);
        } finally {
            chmod($path, 0644);
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
    public function it_handles_symbolic_links_correctly(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->markTestSkipped('Symbolic link test is not available on Windows.');
        }

        $linkPath = $this->testFilesPath . '/link.png';
        symlink($this->testFilesPath . '/image.png', $linkPath);

        $result = $this->service->fromLocal($linkPath);
        $this->assertEquals(['width' => 10, 'height' => 10], $result);
    }

    /** @test */
    public function it_throws_for_empty_file(): void
    {
        $path = $this->testFilesPath . '/empty.png';
        touch($path);
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('File is empty');
        $this->service->fromLocal($path);
    }

    // --- URL ---

    /** @test */
    public function it_handles_network_timeouts(): void
    {
        $url = 'https://example.com/timeout.jpg';
        Http::fake([
            $url => function () {
                throw new ConnectionException('Timeout was reached');
            },
        ]);

        $this->expectException(UrlAccessException::class);
        $this->expectExceptionMessage('Could not download full content from URL: https://example.com/timeout.jpg');
        $this->service->fromUrl($url);
    }

    /** @test */
    public function it_handles_redirects(): void
    {
        $url1 = 'https://example.com/redirect.jpg';
        $url2 = 'https://example.com/final.png';
        $imageData = file_get_contents($this->testFilesPath . '/image.png');

        Http::fake([
            $url1 => Http::response(null, 302, ['Location' => $url2]),
            $url2 => Http::response($imageData, 200),
        ]);

        $result = $this->service->fromUrl($url1);
        $this->assertEquals(['width' => 10, 'height' => 10], $result);
    }

    /** @test */
    public function it_throws_for_too_many_redirects(): void
    {
        // Laravel's HTTP client handles redirect loops automatically and throws an exception.
        $url = 'https://example.com/redirect-loop';
        Http::fake([
            $url => Http::response(null, 302, ['Location' => $url]),
        ]);

        $this->expectException(UrlAccessException::class);
        $this->service->fromUrl($url);
    }

    // --- STORAGE ---

    /** @test */
    public function it_throws_for_non_existent_storage_disk(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->fromStorage('non-existent-disk', 'image.jpg');
    }

    /** @test */
    public function it_throws_if_storage_stream_cannot_be_read(): void
    {
        $diskName = 's3_mock';
        $path = 'image.png';

        $mockDisk = Mockery::mock(Filesystem::class);
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
    public function it_throws_if_storage_full_content_cannot_be_read(): void
    {
        $diskName = 's3_mock';
        $path = 'image.png';

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'invalid stream data');
        rewind($stream);

        $mockDisk = Mockery::mock(Filesystem::class);
        $mockDisk->shouldReceive('exists')->with($path)->andReturn(true);
        $mockDisk->shouldReceive('getAdapter')->andReturn(new \stdClass());
        $mockDisk->shouldReceive('lastModified')->with($path)->andReturn(time());
        $mockDisk->shouldReceive('readStream')->with($path)->andReturn($stream);
        $mockDisk->shouldReceive('get')->with($path)->andReturn(null);

        Storage::shouldReceive('disk')->with($diskName)->andReturn($mockDisk);

        $this->expectException(StorageAccessException::class);
        $this->expectExceptionMessage("Could not read full content from storage file: {$path}");
        $this->service->fromStorage($diskName, $path);
    }

    // --- CONFIG ---

    /** @test */
    public function it_throws_if_temp_dir_is_not_writable(): void
    {
        $invalidDir = $this->testFilesPath . '/unwritable';
        mkdir($invalidDir, 0444); // Read-only
        config(['image-dimensions.temp_dir' => $invalidDir]);
        $service = new ImageDimensionsService();

        $this->expectException(TemporaryFileException::class);
        $this->expectExceptionMessage('Could not create temporary file');

        try {
            // fromUrl использует временные файлы
            Http::fake(['https://example.com/image.png' => Http::response('data')]);
            $service->fromUrl('https://example.com/image.png');
        } finally {
            rmdir($invalidDir);
        }
    }
}