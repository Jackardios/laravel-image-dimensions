<?php

namespace Jackardios\ImageDimensions\Tests\Unit;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\UrlAccessException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

class ImageDimensionsServiceTest extends TestCase
{
    protected ImageDimensionsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ImageDimensionsService;
    }

    #[Test]
    public function it_gets_dimensions_from_local_raster_images(): void
    {
        $this->assertSame(['width' => 100, 'height' => 200], $this->service->fromLocal($this->createImage('test.png', 100, 200)));
        $this->assertSame(['width' => 300, 'height' => 400], $this->service->fromLocal($this->createImage('test.jpg', 300, 400, 'jpg')));
        $this->assertSame(['width' => 150, 'height' => 250], $this->service->fromLocal($this->createImage('test.gif', 150, 250, 'gif')));
        $this->assertSame(['width' => 200, 'height' => 300], $this->service->fromLocal($this->createImage('test.webp', 200, 300, 'webp')));
        $this->assertSame(['width' => 60, 'height' => 40], $this->service->fromLocal($this->createImage('test.bmp', 60, 40, 'bmp')));
    }

    #[Test]
    public function it_throws_exception_for_non_existent_local_file(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->service->fromLocal($this->tempPath.'/non-existent.jpg');
    }

    #[Test]
    public function it_throws_exception_for_empty_path(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Path must be a non-empty string');
        $this->service->fromLocal('  ');
    }

    #[Test]
    public function it_throws_exception_for_empty_file(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('File is empty');
        $this->service->fromLocal($this->createFile('empty.png', ''));
    }

    #[Test]
    public function it_throws_exception_for_invalid_image_file(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Could not determine image dimensions');
        $this->service->fromLocal($this->createFile('invalid.jpg', 'not an image'));
    }

    #[Test]
    public function it_handles_symbolic_links(): void
    {
        $target = $this->createImage('test.png', 100, 200);
        $link = $this->tempPath.'/link.png';

        if (! @symlink($target, $link)) {
            $this->markTestSkipped('Creating symbolic links is not permitted in this environment.');
        }

        $this->assertSame(['width' => 100, 'height' => 200], $this->service->fromLocal($link));
    }

    #[Test]
    public function it_determines_the_format_from_contents_not_the_extension(): void
    {
        $png = $this->createImage('test.png', 100, 200);
        $disguised = $this->createFile('fake.jpg', file_get_contents($png));

        $this->assertSame(['width' => 100, 'height' => 200], $this->service->fromLocal($disguised));
    }

    #[Test]
    public function it_gets_dimensions_from_svg_width_and_height(): void
    {
        $this->assertSame(['width' => 500, 'height' => 600], $this->service->fromLocal($this->createSvg('a.svg', ['width' => '500', 'height' => '600'])));
        $this->assertSame(['width' => 150, 'height' => 250], $this->service->fromLocal($this->createSvg('b.svg', ['width' => '150px', 'height' => '250px'])));
        $this->assertSame(['width' => 11, 'height' => 21], $this->service->fromLocal($this->createSvg('c.svg', ['width' => '10.2', 'height' => '20.01'])));
    }

    #[Test]
    public function it_falls_back_to_the_viewbox(): void
    {
        $this->assertSame(['width' => 400, 'height' => 300], $this->service->fromLocal($this->createSvg('a.svg', ['viewBox' => '0 0 400 300'])));
        $this->assertSame(['width' => 800, 'height' => 600], $this->service->fromLocal($this->createSvg('b.svg', ['width' => '100%', 'height' => '100%', 'viewBox' => '0,0,800,600'])));
    }

    #[Test]
    public function it_throws_exception_for_svg_without_dimensions_and_viewbox(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Could not determine SVG dimensions');
        $this->service->fromLocal($this->createSvg('no-dims.svg', []));
    }

    #[Test]
    public function it_throws_exception_for_malformed_svg(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->service->fromLocal($this->createFile('malformed.svg', '<?xml version="1.0"?><svg><rect/>'));
    }

    #[Test]
    public function it_ignores_scripts_and_event_handlers_in_svg(): void
    {
        $path = $this->createFile('scripted.svg', '<?xml version="1.0"?>'
            .'<svg width="100" height="100" xmlns="http://www.w3.org/2000/svg">'
            .'<script>alert("XSS")</script><rect onclick="alert(1)" width="100" height="100"/></svg>');

        $this->assertSame(['width' => 100, 'height' => 100], $this->service->fromLocal($path));
    }

    #[Test]
    public function it_rejects_svg_files_larger_than_the_configured_limit(): void
    {
        config(['image-dimensions.svg.max_file_size' => 1024]);
        $service = new ImageDimensionsService;
        $path = $this->createSvg('large.svg', ['width' => '100', 'height' => '100'], str_repeat('<rect width="1" height="1"/>', 100));

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('SVG file is too large');
        $service->fromLocal($path);
    }

    #[Test]
    public function it_validates_url_format(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Invalid URL provided');
        $this->service->fromUrl('not-a-valid-url');
    }

    #[Test]
    public function it_rejects_non_http_urls(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Only HTTP and HTTPS URLs are supported');
        $this->service->fromUrl('file:///etc/passwd');
    }

    #[Test]
    public function it_gets_dimensions_from_valid_url(): void
    {
        $url = 'https://example.com/image.png';
        Http::fake([
            $url => Http::response(Utils::streamFor(file_get_contents($this->createImage('test.png', 100, 200))), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->assertSame(['width' => 100, 'height' => 200], $this->service->fromUrl($url));
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_throws_exception_for_failed_http_request(): void
    {
        $url = 'https://example.com/not-found.jpg';
        Http::fake([$url => Http::response(null, 404)]);

        $this->expectException(UrlAccessException::class);
        $this->expectExceptionMessage("Could not open URL: {$url}");
        $this->service->fromUrl($url);
    }

    #[Test]
    public function it_follows_http_redirects(): void
    {
        $redirectUrl = 'https://example.com/redirect.jpg';
        $finalUrl = 'https://example.com/image.jpg';
        Http::fake([
            $redirectUrl => Http::response(null, 302, ['Location' => $finalUrl]),
            $finalUrl => Http::response(Utils::streamFor(file_get_contents($this->createImage('test.jpg', 300, 400, 'jpg'))), 200),
        ]);

        $this->assertSame(['width' => 300, 'height' => 400], $this->service->fromUrl($redirectUrl));
    }

    #[Test]
    public function it_validates_storage_disk_name_and_path(): void
    {
        try {
            $this->service->fromStorage(' ', 'image.jpg');
            $this->fail('An empty disk name must be rejected.');
        } catch (InvalidImageException $e) {
            $this->assertStringContainsString('Disk name must be a non-empty string', $e->getMessage());
        }

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Path must be a non-empty string');
        $this->service->fromStorage('local', ' ');
    }

    #[Test]
    public function it_throws_exception_for_non_existent_storage_disk(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage("Storage disk 'non-existent' does not exist");
        $this->service->fromStorage('non-existent', 'image.jpg');
    }

    #[Test]
    public function it_gets_dimensions_from_a_local_storage_disk(): void
    {
        Storage::fake('test-disk');
        Storage::disk('test-disk')->put('image.jpg', file_get_contents($this->createImage('test.jpg', 300, 400, 'jpg')));

        $this->assertSame(['width' => 300, 'height' => 400], $this->service->fromStorage('test-disk', 'image.jpg'));
    }

    #[Test]
    public function it_throws_exception_for_non_existent_storage_file(): void
    {
        Storage::fake('test-disk');

        $this->expectException(FileNotFoundException::class);
        $this->service->fromStorage('test-disk', 'non-existent.jpg');
    }

    #[Test]
    public function it_throws_exception_for_non_existent_file_on_a_non_local_disk(): void
    {
        $this->useInMemoryDisk('remote');

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage("File not found on disk 'remote': missing.png");
        $this->service->fromStorage('remote', 'missing.png');
    }

    #[Test]
    public function it_delegates_local_disks_to_the_local_lookup(): void
    {
        config(['image-dimensions.enable_cache' => true]);
        $service = new ImageDimensionsService;
        Storage::fake('test-disk');
        Storage::disk('test-disk')->put('image.png', file_get_contents($this->createImage('test.png', 30, 40)));
        $path = realpath(Storage::disk('test-disk')->path('image.png'));

        $this->assertSame(['width' => 30, 'height' => 40], $service->fromStorage('test-disk', 'image.png'));
        // Shares the fromLocal() cache entry: no temp copy, no storage key.
        $this->assertTrue(Cache::has('image_dimensions:v1.1:local:'.md5($path).':'.filemtime($path)));
    }

    #[Test]
    public function it_caches_local_lookups_under_a_path_and_mtime_key(): void
    {
        config(['image-dimensions.enable_cache' => true, 'image-dimensions.cache_ttl' => 120]);
        $service = new ImageDimensionsService;
        $path = $this->createImage('test.png', 100, 200);
        $cacheKey = 'image_dimensions:v1.1:local:'.md5(realpath($path)).':'.filemtime($path);

        Cache::shouldReceive('remember')
            ->once()
            ->with($cacheKey, 120, Mockery::type('callable'))
            ->andReturn(['width' => 1, 'height' => 2]);

        $this->assertSame(['width' => 1, 'height' => 2], $service->fromLocal($path));
    }

    #[Test]
    public function it_does_not_use_cache_when_disabled(): void
    {
        config(['image-dimensions.enable_cache' => false]);
        $service = new ImageDimensionsService;

        Cache::shouldReceive('remember')->never();

        $this->assertSame(['width' => 100, 'height' => 200], $service->fromLocal($this->createImage('test.png', 100, 200)));
    }

    #[Test]
    public function it_clamps_remote_read_bytes_to_its_bounds(): void
    {
        $read = fn (ImageDimensionsService $service): int => (fn () => $this->remoteReadBytes)->call($service);

        config(['image-dimensions.remote_read_bytes' => 100]);
        $this->assertSame(8192, $read(new ImageDimensionsService));

        config(['image-dimensions.remote_read_bytes' => 10000000]);
        $this->assertSame(1048576, $read(new ImageDimensionsService));

        config(['image-dimensions.remote_read_bytes' => 16384]);
        $this->assertSame(16384, $read(new ImageDimensionsService));
    }
}
