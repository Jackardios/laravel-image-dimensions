<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\UrlAccessException;
use Jackardios\ImageDimensions\Facades\ImageDimensions;
use Jackardios\ImageDimensions\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

class ImageDimensionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create a dummy image for local and storage tests
        Storage::fake("local");
        Storage::fake("public");

        $this->createTestImage("test.jpg", 100, 50);
        $this->createTestImage("test.png", 200, 150);
        $this->createTestImage("test.gif", 300, 250);

        // Create a dummy image that requires full download for dimensions
        // This is a simplified representation. In a real scenario, you might craft
        // a specific image file that has its dimensions metadata at the end.
        // For testing purposes, we'll create a small valid image and then
        // simulate a partial read that fails, and a full read that succeeds.
        $this->createTestImage("partial_fail.jpg", 60, 40);
    }

    protected function createTestImage(string $filename, int $width, int $height, string $type = "jpeg"): void
    {
        $image = imagecreatetruecolor($width, $height);
        $path = Storage::disk("local")->path($filename);

        match (strtolower($type)) {
            "png" => imagepng($image, $path),
            "gif" => imagegif($image, $path),
            default => imagejpeg($image, $path),
        };
        imagedestroy($image);

        // Also put it in the public disk for storage tests
        Storage::disk("public")->put($filename, file_get_contents($path));
    }

    /** @test */
    public function it_can_get_dimensions_from_local_file()
    {
        $path = Storage::disk("local")->path("test.jpg");
        $dimensions = ImageDimensions::fromLocal($path);

        $this->assertEquals(100, $dimensions['width']);
        $this->assertEquals(50, $dimensions['height']);
    }

    /** @test */
    public function it_throws_exception_for_non_existent_local_file()
    {
        $this->expectException(FileNotFoundException::class);
        ImageDimensions::fromLocal("non_existent_file.jpg");
    }

    /** @test */
    public function it_can_get_dimensions_from_storage_file()
    {
        $dimensions = ImageDimensions::fromStorage("public", "test.png");

        $this->assertEquals(200, $dimensions['width']);
        $this->assertEquals(150, $dimensions['height']);
    }

    /** @test */
    public function it_throws_exception_for_non_existent_storage_file()
    {
        $this->expectException(FileNotFoundException::class);
        ImageDimensions::fromStorage("public", "non_existent_file.jpg");
    }

    /** @test */
    public function it_can_get_dimensions_from_url()
    {
        $localPath = Storage::disk("local")->path("test.gif");
        $fileUrl = "file://" . $localPath;

        $dimensions = ImageDimensions::fromUrl($fileUrl);

        $this->assertEquals(300, $dimensions['width']);
        $this->assertEquals(250, $dimensions['height']);
    }

    /** @test */
    public function it_throws_exception_for_invalid_url()
    {
        $this->expectException(UrlAccessException::class);
        ImageDimensions::fromUrl("invalid-url");
    }

    /** @test */
    public function it_throws_exception_for_url_not_pointing_to_image()
    {
        $this->expectException(InvalidImageException::class);
        ImageDimensions::fromUrl("file:///dev/null");
    }

    /** @test */
    public function it_handles_different_image_formats_from_local()
    {
        $jpgPath = Storage::disk("local")->path("test.jpg");
        $pngPath = Storage::disk("local")->path("test.png");
        $gifPath = Storage::disk("local")->path("test.gif");

        $jpgDimensions = ImageDimensions::fromLocal($jpgPath);
        $this->assertEquals(100, $jpgDimensions['width']);
        $this->assertEquals(50, $jpgDimensions['height']);

        $pngDimensions = ImageDimensions::fromLocal($pngPath);
        $this->assertEquals(200, $pngDimensions['width']);
        $this->assertEquals(150, $pngDimensions['height']);

        $gifDimensions = ImageDimensions::fromLocal($gifPath);
        $this->assertEquals(300, $gifDimensions['width']);
        $this->assertEquals(250, $gifDimensions['height']);
    }

    /** @test */
    public function it_handles_different_image_formats_from_storage()
    {
        $jpgDimensions = ImageDimensions::fromStorage("public", "test.jpg");
        $this->assertEquals(100, $jpgDimensions['width']);
        $this->assertEquals(50, $jpgDimensions['height']);

        $pngDimensions = ImageDimensions::fromStorage("public", "test.png");
        $this->assertEquals(200, $pngDimensions['width']);
        $this->assertEquals(150, $pngDimensions['height']);

        $gifDimensions = ImageDimensions::fromStorage("public", "test.gif");
        $this->assertEquals(300, $gifDimensions['width']);
        $this->assertEquals(250, $gifDimensions['height']);
    }

    /** @test */
    public function it_handles_different_image_formats_from_url()
    {
        $jpgUrl = "file://" . Storage::disk("local")->path("test.jpg");
        $pngUrl = "file://" . Storage::disk("local")->path("test.png");
        $gifUrl = "file://" . Storage::disk("local")->path("test.gif");

        $jpgDimensions = ImageDimensions::fromUrl($jpgUrl);
        $this->assertEquals(100, $jpgDimensions['width']);
        $this->assertEquals(50, $jpgDimensions['height']);

        $pngDimensions = ImageDimensions::fromUrl($pngUrl);
        $this->assertEquals(200, $pngDimensions['width']);
        $this->assertEquals(150, $pngDimensions['height']);

        $gifDimensions = ImageDimensions::fromUrl($gifUrl);
        $this->assertEquals(300, $gifDimensions['width']);
        $this->assertEquals(250, $gifDimensions['height']);
    }

    /** @test */
    public function it_uses_fallback_for_url_if_partial_read_fails()
    {
        // For this test, we need to simulate a scenario where getimagesize fails on partial data
        // but succeeds on full data. Since `getimagesize` behavior is hard to control for partial reads,
        // we'll simulate this by temporarily setting `remote_read_bytes` to a very small number
        // that would typically not contain image headers, forcing the fallback.

        config(["image-dimensions.remote_read_bytes" => 10]); // Set to a very small value

        $localPath = Storage::disk("local")->path("partial_fail.jpg");
        $fileUrl = "file://" . $localPath;

        $dimensions = ImageDimensions::fromUrl($fileUrl);

        $this->assertEquals(60, $dimensions['width']);
        $this->assertEquals(40, $dimensions['height']);
    }

    /** @test */
    public function it_uses_fallback_for_storage_if_partial_read_fails()
    {
        config(["image-dimensions.remote_read_bytes" => 10]); // Set to a very small value

        $dimensions = ImageDimensions::fromStorage("public", "partial_fail.jpg");

        $this->assertEquals(60, $dimensions['width']);
        $this->assertEquals(40, $dimensions['height']);
    }
}
