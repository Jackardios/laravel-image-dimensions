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

class ImageDimensionsServiceTest extends TestCase
{
    protected ImageDimensionsService $service;
    protected string $fixturesPath;
    protected array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImageDimensionsService();
        $this->fixturesPath = sys_get_temp_dir() . '/imgdim_test_' . uniqid();

        if (!is_dir($this->fixturesPath)) {
            mkdir($this->fixturesPath, 0777, true);
        }

        $this->createTestImages();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
        parent::tearDown();
    }

    /**
     * Create a test image with specified dimensions
     */
    protected function createImage(string $filename, int $width, int $height, string $format = 'png'): string
    {
        $path = $this->fixturesPath . '/' . $filename;
        $image = imagecreatetruecolor($width, $height);

        // Fill with white background
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);

        // Save based on format
        switch ($format) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($image, $path, 95);
                break;
            case 'gif':
                imagegif($image, $path);
                break;
            case 'webp':
                imagewebp($image, $path, 95);
                break;
            case 'bmp':
                imagebmp($image, $path);
                break;
            case 'png':
            default:
                imagepng($image, $path);
                break;
        }

        imagedestroy($image);
        $this->createdFiles[] = $path;

        return $path;
    }

    /**
     * Create an SVG file with specified dimensions
     */
    protected function createSvg(string $filename, array $attributes, string $content = ''): string
    {
        $path = $this->fixturesPath . '/' . $filename;

        $attrs = '';
        foreach ($attributes as $key => $value) {
            $attrs .= " {$key}=\"{$value}\"";
        }

        $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg"' . $attrs . '>' . "\n";
        $svg .= $content ?: '<rect width="100%" height="100%" fill="red"/>';
        $svg .= "\n" . '</svg>';

        file_put_contents($path, $svg);
        $this->createdFiles[] = $path;

        return $path;
    }

    protected function createTestImages(): void
    {
        // Create various test images
        $this->createImage('test.png', 100, 200);
        $this->createImage('test.jpg', 300, 400, 'jpg');
        $this->createImage('test.gif', 150, 250, 'gif');
        $this->createImage('test.webp', 200, 300, 'webp');

        // Create various SVG files
        $this->createSvg('test.svg', ['width' => '500', 'height' => '600']);
        $this->createSvg('test-viewbox.svg', ['viewBox' => '0 0 400 300']);
        $this->createSvg('test-px.svg', ['width' => '150px', 'height' => '250px']);
        $this->createSvg('test-percent.svg', ['width' => '100%', 'height' => '100%', 'viewBox' => '0 0 800 600']);

        // Create invalid files
        file_put_contents($this->fixturesPath . '/invalid.jpg', 'not an image');
        touch($this->fixturesPath . '/empty.png');

        $this->createdFiles[] = $this->fixturesPath . '/invalid.jpg';
        $this->createdFiles[] = $this->fixturesPath . '/empty.png';
    }

    protected function cleanupFixtures(): void
    {
        // Clean up created files
        foreach ($this->createdFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        // Remove directory if empty
        if (is_dir($this->fixturesPath)) {
            @rmdir($this->fixturesPath);
        }
    }

    /** @test */
    public function it_gets_dimensions_from_local_png(): void
    {
        $result = $this->service->fromLocal($this->fixturesPath . '/test.png');
        $this->assertEquals(['width' => 100, 'height' => 200], $result);
    }

    /** @test */
    public function it_gets_dimensions_from_local_jpeg(): void
    {
        $result = $this->service->fromLocal($this->fixturesPath . '/test.jpg');
        $this->assertEquals(['width' => 300, 'height' => 400], $result);
    }

    /** @test */
    public function it_gets_dimensions_from_local_gif(): void
    {
        $result = $this->service->fromLocal($this->fixturesPath . '/test.gif');
        $this->assertEquals(['width' => 150, 'height' => 250], $result);
    }

    /** @test */
    public function it_throws_exception_for_non_existent_local_file(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->service->fromLocal($this->fixturesPath . '/non-existent.jpg');
    }

    /** @test */
    public function it_throws_exception_for_empty_path(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Path must be a non-empty string');
        $this->service->fromLocal('');
    }

    /** @test */
    public function it_throws_exception_for_empty_file(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('File is empty');
        $this->service->fromLocal($this->fixturesPath . '/empty.png');
    }

    /** @test */
    public function it_throws_exception_for_invalid_image_file(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->service->fromLocal($this->fixturesPath . '/invalid.jpg');
    }

    /** @test */
    public function it_handles_symbolic_links(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->markTestSkipped('Symbolic links test skipped on Windows');
        }

        $linkPath = $this->fixturesPath . '/link.png';
        symlink($this->fixturesPath . '/test.png', $linkPath);
        $this->createdFiles[] = $linkPath;

        $result = $this->service->fromLocal($linkPath);
        $this->assertEquals(['width' => 100, 'height' => 200], $result);
    }

    /** @test */
    public function it_gets_dimensions_from_svg_with_width_height(): void
    {
        $result = $this->service->fromLocal($this->fixturesPath . '/test.svg');
        $this->assertEquals(['width' => 500, 'height' => 600], $result);
    }

    /** @test */
    public function it_gets_dimensions_from_svg_with_px_units(): void
    {
        $result = $this->service->fromLocal($this->fixturesPath . '/test-px.svg');
        $this->assertEquals(['width' => 150, 'height' => 250], $result);
    }

    /** @test */
    public function it_falls_back_to_viewbox_for_svg_without_dimensions(): void
    {
        $result = $this->service->fromLocal($this->fixturesPath . '/test-viewbox.svg');
        $this->assertEquals(['width' => 400, 'height' => 300], $result);
    }

    /** @test */
    public function it_falls_back_to_viewbox_for_svg_with_percentage_dimensions(): void
    {
        $result = $this->service->fromLocal($this->fixturesPath . '/test-percent.svg');
        $this->assertEquals(['width' => 800, 'height' => 600], $result);
    }

    /** @test */
    public function it_throws_exception_for_svg_without_dimensions_and_viewbox(): void
    {
        $path = $this->createSvg('no-dims.svg', []);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Could not determine SVG dimensions');
        $this->service->fromLocal($path);
    }

    /** @test */
    public function it_throws_exception_for_malformed_svg(): void
    {
        $path = $this->fixturesPath . '/malformed.svg';
        file_put_contents($path, '<?xml version="1.0"?><svg><rect/>');
        $this->createdFiles[] = $path;

        $this->expectException(InvalidImageException::class);
        $this->service->fromLocal($path);
    }

    /** @test */
    public function it_sanitizes_dangerous_svg_content(): void
    {
        $dangerousSvg = '<?xml version="1.0"?>' .
            '<svg width="100" height="100" xmlns="http://www.w3.org/2000/svg">' .
            '<script>alert("XSS" )</script>' .
            '<rect onclick="alert(1)" width="100" height="100"/>' .
            '</svg>';

        $path = $this->fixturesPath . '/dangerous.svg';
        file_put_contents($path, $dangerousSvg);
        $this->createdFiles[] = $path;

        // Should not throw and should return dimensions
        $result = $this->service->fromLocal($path);
        $this->assertEquals(['width' => 100, 'height' => 100], $result);
    }

    /** @test */
    public function it_rejects_svg_files_that_are_too_large(): void
    {
        // Create SVG larger than 10MB limit
        $largeSvg = '<?xml version="1.0"?><svg width="100" height="100">';
        $largeSvg .= str_repeat('<rect width="1" height="1"/>', 500000);
        $largeSvg .= '</svg>';

        $path = $this->fixturesPath . '/large.svg';
        file_put_contents($path, $largeSvg);
        $this->createdFiles[] = $path;

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('SVG file is too large');
        $this->service->fromLocal($path);
    }

    /** @test */
    public function it_validates_url_format(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Invalid URL provided');
        $this->service->fromUrl('not-a-valid-url');
    }public function it_rejects_non_http_urls( ): void
{
    $this->expectException(InvalidImageException::class);
    $this->expectExceptionMessage('Only HTTP and HTTPS URLs are supported');
    $this->service->fromUrl('file:///etc/passwd');
}

    /** @test */
    public function it_gets_dimensions_from_valid_url(): void
    {
        $url = 'https://example.com/image.png';
        $imageData = file_get_contents($this->fixturesPath . '/test.png' );

        Http::fake([
            $url => Http::response(
                Utils::streamFor($imageData),
                200,
                ['Content-Type' => 'image/png']
            ),
        ]);

        $result = $this->service->fromUrl($url);
        $this->assertEquals(['width' => 100, 'height' => 200], $result);
    }

    /** @test */
    public function it_throws_exception_for_failed_http_request( ): void
    {
        $url = 'https://example.com/not-found.jpg';

        Http::fake([
            $url => Http::response(null, 404 ),
        ]);

        $this->expectException(UrlAccessException::class);
        $this->service->fromUrl($url);
    }

    /** @test */
    public function it_handles_http_redirects(): void
    {
        $redirectUrl = 'https://example.com/redirect.jpg';
        $finalUrl = 'https://example.com/image.jpg';
        $imageData = file_get_contents($this->fixturesPath . '/test.jpg');

        Http::fake([
            $redirectUrl => Http::response(null, 302, ['Location' => $finalUrl]),
            $finalUrl => Http::response(
                Utils::streamFor($imageData),
                200,
                ['Content-Type' => 'image/jpeg']
            ),
        ]);

        $result = $this->service->fromUrl($redirectUrl);
        $this->assertEquals(['width' => 300, 'height' => 400], $result);
    }

    /** @test */
    public function it_validates_storage_disk_name(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Disk name must be a non-empty string');
        $this->service->fromStorage('', 'image.jpg');
    }

    /** @test */
    public function it_validates_storage_path(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Path must be a non-empty string');
        $this->service->fromStorage('local', '');
    }

    /** @test */
    public function it_throws_exception_for_non_existent_storage_disk(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage("Storage disk 'non-existent' does not exist");
        $this->service->fromStorage('non-existent', 'image.jpg');
    }

    /** @test */
    public function it_gets_dimensions_from_storage_file(): void
    {
        Storage::fake('test-disk');
        $imageData = file_get_contents($this->fixturesPath . '/test.jpg');
        Storage::disk('test-disk')->put('image.jpg', $imageData);

        $result = $this->service->fromStorage('test-disk', 'image.jpg');
        $this->assertEquals(['width' => 300, 'height' => 400], $result);
    }

    /** @test */
    public function it_throws_exception_for_non_existent_storage_file(): void
    {
        Storage::fake('test-disk');

        $this->expectException(FileNotFoundException::class);
        $this->service->fromStorage('test-disk', 'non-existent.jpg');
    }

    /** @test */
    public function it_uses_cache_when_enabled(): void
    {
        config(['image-dimensions.enable_cache' => true]);

        $path = $this->fixturesPath . '/test.png';
        $cacheKey = 'image_dimensions:local:' . md5(realpath($path)) . ':' . filemtime($path);

        Cache::shouldReceive('remember')
            ->once()
            ->with($cacheKey, 3600, \Mockery::type('callable'))
            ->andReturn(['width' => 100, 'height' => 200]);

        $result = $this->service->fromLocal($path);
        $this->assertEquals(['width' => 100, 'height' => 200], $result);
    }

    /** @test */
    public function it_does_not_use_cache_when_disabled(): void
    {
        config(['image-dimensions.enable_cache' => false]);
        $service = new ImageDimensionsService();

        Cache::shouldReceive('remember')->never();

        $result = $service->fromLocal($this->fixturesPath . '/test.png');
        $this->assertEquals(['width' => 100, 'height' => 200], $result);
    }

    /** @test */
    public function it_correctly_reads_image_with_wrong_extension(): void
    {
        // Copy PNG as JPEG
        $sourcePath = $this->fixturesPath . '/test.png';
        $destPath = $this->fixturesPath . '/fake.jpg';
        copy($sourcePath, $destPath);
        $this->createdFiles[] = $destPath;

        $result = $this->service->fromLocal($destPath);
        $this->assertEquals(['width' => 100, 'height' => 200], $result);
    }

    /** @test */
    public function it_validates_configuration_bounds(): void
    {
        // Test with extreme values
        config(['image-dimensions.remote_read_bytes' => 100]);
        $service1 = new ImageDimensionsService();

        $reflection = new \ReflectionClass($service1);
        $prop = $reflection->getProperty('remoteReadBytes');
        $prop->setAccessible(true);

        // Should be clamped to minimum 8192
        $this->assertGreaterThanOrEqual(8192, $prop->getValue($service1));

        // Test with very large value
        config(['image-dimensions.remote_read_bytes' => 10000000]);
        $service2 = new ImageDimensionsService();

        // Should be clamped to maximum 1048576
        $this->assertLessThanOrEqual(1048576, $prop->getValue($service2));
    }

    /** @test */
    public function it_handles_webp_format(): void
    {
        $result = $this->service->fromLocal($this->fixturesPath . '/test.webp');
        $this->assertEquals(['width' => 200, 'height' => 300], $result);
    }
}