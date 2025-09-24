<?php

namespace Jackardios\ImageDimensions\Tests\Unit;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImageDimensionsService();
        $this->fixturesPath = __DIR__ . '/fixtures';
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

    protected function createTestImages(): void
    {
        // Create a simple PNG image
        $png = imagecreate(100, 200);
        imagecolorallocate($png, 255, 255, 255);
        imagepng($png, $this->fixturesPath . '/test.png');
        imagedestroy($png);

        // Create a JPEG image
        $jpeg = imagecreate(300, 400);
        imagecolorallocate($jpeg, 255, 255, 255);
        imagejpeg($jpeg, $this->fixturesPath . '/test.jpg');
        imagedestroy($jpeg);

        // Create a GIF image
        $gif = imagecreate(150, 250);
        imagecolorallocate($gif, 255, 255, 255);
        imagegif($gif, $this->fixturesPath . '/test.gif');
        imagedestroy($gif);

        // Create WebP image if supported
        if (function_exists('imagewebp')) {
            $webp = imagecreatetruecolor(200, 300);
            $white = imagecolorallocate($webp, 255, 255, 255);
            imagefill($webp, 0, 0, $white);
            imagewebp($webp, $this->fixturesPath . '/test.webp');
            imagedestroy($webp);
        }

        // Create a simple SVG
        $svg = '<?xml version="1.0"?>
            <svg width="500" height="600" xmlns="http://www.w3.org/2000/svg">
                <rect width="500" height="600" fill="red"/>
            </svg>';
        file_put_contents($this->fixturesPath . '/test.svg', $svg);

        // Create SVG with viewBox only
        $svgViewBox = '<?xml version="1.0"?>
            <svg viewBox="0 0 400 300" xmlns="http://www.w3.org/2000/svg">
                <rect width="400" height="300" fill="blue"/>
            </svg>';
        file_put_contents($this->fixturesPath . '/test-viewbox.svg', $svgViewBox);

        // Create SVG with units
        $svgUnits = '<?xml version="1.0"?>
            <svg width="100pt" height="200pt" xmlns="http://www.w3.org/2000/svg">
                <rect width="100" height="200" fill="green"/>
            </svg>';
        file_put_contents($this->fixturesPath . '/test-units.svg', $svgUnits);

        // Create invalid image file
        file_put_contents($this->fixturesPath . '/invalid.jpg', 'not an image');

        // Create empty file
        touch($this->fixturesPath . '/empty.png');
    }

    protected function cleanupFixtures(): void
    {
        if (is_dir($this->fixturesPath)) {
            $files = glob($this->fixturesPath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
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
    public function it_throws_exception_for_non_existent_local_file(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->service->fromLocal('non-existent.jpg');
    }

    /** @test */
    public function it_gets_dimensions_from_svg_with_px_units(): void
    {
        $svg = '<svg width="150px" height="250px"></svg>';
        file_put_contents($this->fixturesPath . '/test_px.svg', $svg);
        $result = $this->service->fromLocal($this->fixturesPath . '/test_px.svg');
        $this->assertEquals(['width' => 150, 'height' => 250], $result);
    }

    /** @test */
    public function it_falls_back_to_viewbox_if_svg_units_are_not_px(): void
    {
        $svg = '<svg width="100%" height="100pt" viewBox="0 0 800 600"></svg>';
        file_put_contents($this->fixturesPath . '/test_mixed.svg', $svg);
        $result = $this->service->fromLocal($this->fixturesPath . '/test_mixed.svg');
        $this->assertEquals(['width' => 800, 'height' => 600], $result);
    }

    /** @test */
    public function it_can_get_dimensions_from_url_successfully(): void
    {
        $url = 'https://example.com/image.png';
        $imageData = file_get_contents($this->fixturesPath . '/test.png');

        Http::fake([
            $url => Http::response($imageData, 200),
        ]);

        $result = $this->service->fromUrl($url);

        $this->assertEquals(['width' => 100, 'height' => 200], $result);
    }

    /** @test */
    public function it_handles_url_that_fails_partial_read_but_succeeds_full_read(): void
    {
        // Симулируем PNG, у которого размеры не определяются по первым 64кб
        // Для этого создадим "сломанный" заголовок
        $imageData = file_get_contents($this->fixturesPath . '/test.png');
        $partialData = str_repeat('A', 65536) . substr($imageData, 65536);

        $url = 'https://example.com/image.png';

        // Мокируем два ответа: один для fopen (частичный), другой для Http::get (полный)
        // Для этого теста проще всего будет переопределить сервис
        $mockService = new class extends ImageDimensionsService {
            protected function getStreamFromUrl(string $url) {
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, str_repeat('A', 65536)); // "Сломанные" данные
                rewind($stream);
                return $stream;
            }
            protected function getFullContentFromUrl(string $url): string {
                // Возвращаем корректные данные
                return file_get_contents(__DIR__ . '/fixtures/test.png');
            }
        };

        $result = $mockService->fromUrl($url);
        $this->assertEquals(['width' => 100, 'height' => 200], $result);
    }

    /** @test */
    public function it_throws_exception_for_404_url(): void
    {
        $url = 'https://example.com/not-found.jpg';
        Http::fake([
            $url => Http::response(null, 404),
        ]);

        $this->expectException(UrlAccessException::class);
        $this->service->fromUrl($url);
    }

    /** @test */
    public function it_throws_exception_for_invalid_url(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->service->fromUrl('not-a-valid-url');
    }

    /** @test */
    public function it_gets_dimensions_from_local_storage_disk(): void
    {
        Storage::fake('local_disk');
        $imageData = file_get_contents($this->fixturesPath . '/test.jpg');
        Storage::disk('local_disk')->put('image.jpg', $imageData);

        $result = $this->service->fromStorage('local_disk', 'image.jpg');

        $this->assertEquals(['width' => 300, 'height' => 400], $result);
    }

    /** @test */
    public function it_gets_dimensions_from_remote_storage_disk(): void
    {
        Storage::fake('s3_disk');
        $imageData = file_get_contents($this->fixturesPath . '/test.png');
        Storage::disk('s3_disk')->put('image.png', $imageData);

        $result = $this->service->fromStorage('s3_disk', 'image.png');

        $this->assertEquals(['width' => 100, 'height' => 200], $result);
    }

    /** @test */
    public function it_uses_cache_when_enabled(): void
    {
        config(['image-dimensions.enable_cache' => true]);

        $path = $this->fixturesPath . '/test.png';
        $key = 'image_dimensions:local:' . md5($path) . ':' . filemtime($path);

        Cache::shouldReceive('remember')
            ->once()
            ->with($key, 3600, \Mockery::type('callable'))
            ->andReturn(['width' => 100, 'height' => 200]);

        $this->service->fromLocal($path);
    }

    /** @test */
    public function it_does_not_use_cache_when_disabled(): void
    {
        config(['image-dimensions.enable_cache' => false]);
        $service = new ImageDimensionsService();

        Cache::shouldReceive('remember')->never();

        $service->fromLocal($this->fixturesPath . '/test.png');
    }

    /** @test */
    public function it_throws_exception_for_svg_without_any_dimensions(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Could not determine SVG dimensions');

        $svg = '<svg></svg>';
        $path = $this->fixturesPath . '/no_dims.svg';
        file_put_contents($path, $svg);

        $this->service->fromLocal($path);
    }

    /** @test */
    public function it_correctly_reads_image_with_wrong_extension(): void
    {
        $sourcePath = $this->fixturesPath . '/test.png';
        $destPath = $this->fixturesPath . '/image.jpg';
        copy($sourcePath, $destPath);

        $result = $this->service->fromLocal($destPath);
        $this->assertEquals(['width' => 100, 'height' => 200], $result);
    }

    /** @test */
    public function it_throws_exception_for_unsupported_format_if_it_cannot_be_read(): void
    {
        config(['image-dimensions.supported_formats' => ['png']]);
        $service = new ImageDimensionsService(); // Re-initialize

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Unsupported image format: txt');

        $path = $this->fixturesPath . '/file.txt';
        file_put_contents($path, 'not an image');
        $service->fromLocal($path);
    }
}