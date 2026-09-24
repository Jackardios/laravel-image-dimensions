<?php

declare(strict_types=1);

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
use PHPUnit\Framework\Attributes\Test;

class ImageDimensionsServiceTest extends TestCase
{
    protected ImageDimensionsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImageDimensionsService;
        $this->createTestImages();
    }

    protected function createTestImages(): void
    {
        $this->createImage('test.png', 100, 200);
        $this->createImage('test.jpg', 300, 400, 'jpg');
        $this->createImage('test.gif', 150, 250, 'gif');
        $this->createImage('test.webp', 200, 300, 'webp');

        $this->createSvg('test.svg', ['width' => '500', 'height' => '600']);
        $this->createSvg('test-viewbox.svg', ['viewBox' => '0 0 400 300']);
        $this->createSvg('test-px.svg', ['width' => '150px', 'height' => '250px']);
        $this->createSvg('test-percent.svg', ['width' => '100%', 'height' => '100%', 'viewBox' => '0 0 800 600']);

        $this->createFile('invalid.jpg', 'not an image');
        $this->createFile('empty.png', '');
    }

    #[Test]
    public function it_gets_dimensions_from_local_png(): void
    {
        $result = $this->service->fromLocal($this->tempPath.'/test.png');
        $this->assertDimensions(100, 200, $result);
    }

    #[Test]
    public function it_gets_dimensions_from_local_jpeg(): void
    {
        $result = $this->service->fromLocal($this->tempPath.'/test.jpg');
        $this->assertDimensions(300, 400, $result);
    }

    #[Test]
    public function it_gets_dimensions_from_local_gif(): void
    {
        $result = $this->service->fromLocal($this->tempPath.'/test.gif');
        $this->assertDimensions(150, 250, $result);
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
        $this->service->fromLocal('');
    }

    #[Test]
    public function it_throws_exception_for_empty_file(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('File is empty');
        $this->service->fromLocal($this->tempPath.'/empty.png');
    }

    #[Test]
    public function it_throws_exception_for_invalid_image_file(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->service->fromLocal($this->tempPath.'/invalid.jpg');
    }

    #[Test]
    public function it_handles_symbolic_links(): void
    {
        $linkPath = $this->tempPath.'/link.png';

        if (! @symlink($this->tempPath.'/test.png', $linkPath)) {
            $this->markTestSkipped('Creating symbolic links is not permitted in this environment.');
        }

        $result = $this->service->fromLocal($linkPath);
        $this->assertDimensions(100, 200, $result);
    }

    #[Test]
    public function it_gets_dimensions_from_svg_with_width_height(): void
    {
        $result = $this->service->fromLocal($this->tempPath.'/test.svg');
        $this->assertDimensions(500, 600, $result);
    }

    #[Test]
    public function it_gets_dimensions_from_svg_with_px_units(): void
    {
        $result = $this->service->fromLocal($this->tempPath.'/test-px.svg');
        $this->assertDimensions(150, 250, $result);
    }

    #[Test]
    public function it_falls_back_to_viewbox_for_svg_without_dimensions(): void
    {
        $result = $this->service->fromLocal($this->tempPath.'/test-viewbox.svg');
        $this->assertDimensions(400, 300, $result);
    }

    #[Test]
    public function it_falls_back_to_viewbox_for_svg_with_percentage_dimensions(): void
    {
        $result = $this->service->fromLocal($this->tempPath.'/test-percent.svg');
        $this->assertDimensions(800, 600, $result);
    }

    #[Test]
    public function it_throws_exception_for_svg_without_dimensions_and_viewbox(): void
    {
        $path = $this->createSvg('no-dims.svg', []);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Could not determine SVG dimensions');
        $this->service->fromLocal($path);
    }

    #[Test]
    public function it_throws_exception_for_malformed_svg(): void
    {
        $path = $this->tempPath.'/malformed.svg';
        file_put_contents($path, '<?xml version="1.0"?><svg><rect/>');

        $this->expectException(InvalidImageException::class);
        $this->service->fromLocal($path);
    }

    #[Test]
    public function it_sanitizes_dangerous_svg_content(): void
    {
        $dangerousSvg = '<?xml version="1.0"?>'.
            '<svg width="100" height="100" xmlns="http://www.w3.org/2000/svg">'.
            '<script>alert("XSS")</script>'.
            '<rect onclick="alert(1)" width="100" height="100"/>'.
            '</svg>';

        $path = $this->tempPath.'/dangerous.svg';
        file_put_contents($path, $dangerousSvg);

        $result = $this->service->fromLocal($path);
        $this->assertDimensions(100, 100, $result);
    }

    #[Test]
    public function it_rejects_svg_files_that_are_too_large(): void
    {
        $largeSvg = '<?xml version="1.0"?><svg width="100" height="100">';
        $largeSvg .= str_repeat('<rect width="1" height="1"/>', 500000);
        $largeSvg .= '</svg>';

        $path = $this->tempPath.'/large.svg';
        file_put_contents($path, $largeSvg);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('SVG file is too large');
        $this->service->fromLocal($path);
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
        $imageData = file_get_contents($this->tempPath.'/test.png');

        Http::fake([
            $url => Http::response(
                Utils::streamFor($imageData),
                200,
                ['Content-Type' => 'image/png']
            ),
        ]);

        $result = $this->service->fromUrl($url);
        $this->assertDimensions(100, 200, $result);
    }

    #[Test]
    public function it_throws_exception_for_failed_http_request(): void
    {
        $url = 'https://example.com/not-found.jpg';

        Http::fake([
            $url => Http::response(null, 404),
        ]);

        $this->expectException(UrlAccessException::class);
        $this->service->fromUrl($url);
    }

    #[Test]
    public function it_handles_http_redirects(): void
    {
        $redirectUrl = 'https://example.com/redirect.jpg';
        $finalUrl = 'https://example.com/image.jpg';
        $imageData = file_get_contents($this->tempPath.'/test.jpg');

        Http::fake([
            $redirectUrl => Http::response(null, 302, ['Location' => $finalUrl]),
            $finalUrl => Http::response(
                Utils::streamFor($imageData),
                200,
                ['Content-Type' => 'image/jpeg']
            ),
        ]);

        $result = $this->service->fromUrl($redirectUrl);
        $this->assertDimensions(300, 400, $result);
    }

    #[Test]
    public function it_validates_storage_disk_name(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Disk name must be a non-empty string');
        $this->service->fromStorage('', 'image.jpg');
    }

    #[Test]
    public function it_validates_storage_path(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Path must be a non-empty string');
        $this->service->fromStorage('local', '');
    }

    #[Test]
    public function it_throws_exception_for_non_existent_storage_disk(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage("Storage disk 'non-existent' does not exist");
        $this->service->fromStorage('non-existent', 'image.jpg');
    }

    #[Test]
    public function it_gets_dimensions_from_storage_file(): void
    {
        Storage::fake('test-disk');
        $imageData = file_get_contents($this->tempPath.'/test.jpg');
        Storage::disk('test-disk')->put('image.jpg', $imageData);

        $result = $this->service->fromStorage('test-disk', 'image.jpg');
        $this->assertDimensions(300, 400, $result);
    }

    #[Test]
    public function it_throws_exception_for_non_existent_storage_file(): void
    {
        Storage::fake('test-disk');

        $this->expectException(FileNotFoundException::class);
        $this->service->fromStorage('test-disk', 'non-existent.jpg');
    }

    #[Test]
    public function it_uses_cache_when_enabled(): void
    {
        config(['image-dimensions.enable_cache' => true]);

        $path = $this->tempPath.'/test.png';

        // A cached entry is returned without looking at the file.
        Cache::shouldReceive('get')
            ->once()
            ->with(\Mockery::pattern('/^image_dimensions:v2:local:[0-9a-f]{32}$/'))
            ->andReturn(['width' => 11, 'height' => 22]);

        $result = $this->service->fromLocal($path);
        $this->assertDimensions(11, 22, $result);
    }

    #[Test]
    public function it_does_not_use_cache_when_disabled(): void
    {
        config(['image-dimensions.enable_cache' => false]);
        $service = new ImageDimensionsService;

        Cache::shouldReceive('get')->never();

        $result = $service->fromLocal($this->tempPath.'/test.png');
        $this->assertDimensions(100, 200, $result);
    }

    #[Test]
    public function it_correctly_reads_image_with_wrong_extension(): void
    {
        $sourcePath = $this->tempPath.'/test.png';
        $destPath = $this->tempPath.'/fake.jpg';
        copy($sourcePath, $destPath);

        $result = $this->service->fromLocal($destPath);
        $this->assertDimensions(100, 200, $result);
    }

    #[Test]
    public function it_validates_configuration_bounds(): void
    {
        config(['image-dimensions.remote_read_bytes' => 100]);
        $service1 = new ImageDimensionsService;

        $reflection = new \ReflectionClass($service1);
        $prop = $reflection->getProperty('remoteReadBytes');

        $this->assertGreaterThanOrEqual(8192, $prop->getValue($service1));

        config(['image-dimensions.remote_read_bytes' => 10000000]);
        $service2 = new ImageDimensionsService;

        $this->assertLessThanOrEqual(1048576, $prop->getValue($service2));
    }

    #[Test]
    public function it_handles_webp_format(): void
    {
        $result = $this->service->fromLocal($this->tempPath.'/test.webp');
        $this->assertDimensions(200, 300, $result);
    }

    #[Test]
    public function it_throws_file_not_found_for_a_directory(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->service->fromLocal($this->tempPath);
    }

    #[Test]
    public function it_reads_viewbox_with_a_non_zero_origin_through_from_local(): void
    {
        $path = $this->tempPath.'/vb-origin.svg';
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="10 20 400 300"><rect/></svg>');

        $this->assertDimensions(400, 300, $this->service->fromLocal($path));
    }

    #[Test]
    public function it_reads_a_namespace_prefixed_svg_through_from_local(): void
    {
        $path = $this->tempPath.'/ns.svg';
        file_put_contents(
            $path,
            '<svg:svg xmlns:svg="http://www.w3.org/2000/svg" width="120" height="90"><svg:rect/></svg:svg>'
        );

        $this->assertDimensions(120, 90, $this->service->fromLocal($path));
    }
}
