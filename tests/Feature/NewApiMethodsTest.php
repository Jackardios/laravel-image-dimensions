<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Jackardios\ImageDimensions\Dimensions;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\Concerns\CreatesTestImages;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class NewApiMethodsTest extends TestCase
{
    use CreatesTestImages;

    private ImageDimensionsService $service;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImageDimensionsService(['enable_cache' => false]);
        $this->dir = sys_get_temp_dir() . '/imgdim_api_' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->cleanupCreatedFiles($this->dir);
        parent::tearDown();
    }

    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $data = (string) ob_get_clean();
        imagedestroy($image);

        return $data;
    }

    // --- fromContents ---

    #[Test]
    public function it_reads_dimensions_from_raster_contents(): void
    {
        $this->assertDimensions(64, 48, $this->service->fromContents($this->pngBytes(64, 48)));
    }

    #[Test]
    public function it_reads_dimensions_from_svg_contents(): void
    {
        $svg = '<!-- x --><svg xmlns="http://www.w3.org/2000/svg" width="200" height="120"><rect/></svg>';
        $this->assertDimensions(200, 120, $this->service->fromContents($svg));
    }

    #[Test]
    public function from_contents_rejects_an_empty_string(): void
    {
        $this->expectException(\Jackardios\ImageDimensions\Exceptions\InvalidImageException::class);
        $this->service->fromContents('');
    }

    // --- fromStream ---

    #[Test]
    public function it_reads_dimensions_from_a_stream(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $this->pngBytes(30, 90));
        rewind($stream);

        $this->assertDimensions(30, 90, $this->service->fromStream($stream));
        fclose($stream);
    }

    // --- fromUploadedFile ---

    #[Test]
    public function it_reads_dimensions_from_an_uploaded_file(): void
    {
        $path = $this->createImage($this->dir, 'upload.png', 220, 140);
        $uploaded = new UploadedFile($path, 'upload.png', 'image/png', null, true);

        $this->assertDimensions(220, 140, $this->service->fromUploadedFile($uploaded));
    }

    #[Test]
    public function it_reads_an_svg_upload_without_a_usable_extension(): void
    {
        // Simulate an upload temp file with no extension but SVG contents.
        $path = $this->dir . '/phpUPLOAD';
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" width="70" height="30"><rect/></svg>');
        $this->createdFiles[] = $path;

        $uploaded = new UploadedFile($path, 'logo.svg', 'image/svg+xml', null, true);

        $this->assertDimensions(70, 30, $this->service->fromUploadedFile($uploaded));
    }

    // --- tryFrom* ---

    #[Test]
    public function try_variants_return_dimensions_on_success(): void
    {
        $path = $this->createImage($this->dir, 'ok.png', 12, 34);

        $this->assertDimensions(12, 34, $this->service->tryFromLocal($path));
        $this->assertDimensions(64, 48, $this->service->tryFromContents($this->pngBytes(64, 48)));
    }

    #[Test]
    public function try_variants_return_null_on_failure(): void
    {
        $this->assertNull($this->service->tryFromLocal($this->dir . '/missing.png'));
        $this->assertNull($this->service->tryFromContents('not an image'));
        $this->assertNull($this->service->tryFromStorage('nonexistent-disk', 'x.png'));

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'garbage');
        rewind($stream);
        $this->assertNull($this->service->tryFromStream($stream));
        fclose($stream);
    }

    #[Test]
    public function try_from_url_returns_null_for_a_blocked_host(): void
    {
        $service = new ImageDimensionsService([
            'enable_cache' => false,
            'url' => ['allow_private_hosts' => false],
        ]);

        $this->assertNull($service->tryFromUrl('http://127.0.0.1/secret.png'));
    }

    #[Test]
    public function the_new_methods_return_the_dimensions_value_object(): void
    {
        $this->assertInstanceOf(Dimensions::class, $this->service->fromContents($this->pngBytes(5, 5)));
    }
}
