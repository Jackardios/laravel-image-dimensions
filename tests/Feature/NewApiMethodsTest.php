<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Jackardios\ImageDimensions\Dimensions;
use Jackardios\ImageDimensions\Exceptions\FileTooLargeException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class NewApiMethodsTest extends TestCase
{
    private ImageDimensionsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImageDimensionsService(['enable_cache' => false]);
    }

    // --- fromContents ---

    #[Test]
    public function it_reads_dimensions_from_raster_contents(): void
    {
        $this->assertDimensions(64, 48, $this->service->fromContents($this->imageBytes(64, 48)));
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
        $this->expectException(InvalidImageException::class);
        $this->service->fromContents('');
    }

    // --- fromStream ---

    #[Test]
    public function it_reads_dimensions_from_a_stream(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $this->imageBytes(30, 90));
        rewind($stream);

        $this->assertDimensions(30, 90, $this->service->fromStream($stream));
        fclose($stream);
    }

    // --- fromUploadedFile ---

    #[Test]
    public function it_reads_dimensions_from_an_uploaded_file(): void
    {
        $path = $this->createImage('upload.png', 220, 140);
        $uploaded = new UploadedFile($path, 'upload.png', 'image/png', null, true);

        $this->assertDimensions(220, 140, $this->service->fromUploadedFile($uploaded));
    }

    #[Test]
    public function it_reads_an_svg_upload_without_a_usable_extension(): void
    {
        // Simulate an upload temp file with no extension but SVG contents.
        $path = $this->tempPath.'/phpUPLOAD';
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" width="70" height="30"><rect/></svg>');

        $uploaded = new UploadedFile($path, 'logo.svg', 'image/svg+xml', null, true);

        $this->assertDimensions(70, 30, $this->service->fromUploadedFile($uploaded));
    }

    // --- tryFrom* ---

    #[Test]
    public function try_variants_return_dimensions_on_success(): void
    {
        $path = $this->createImage('ok.png', 12, 34);

        $this->assertDimensions(12, 34, $this->service->tryFromLocal($path));
        $this->assertDimensions(64, 48, $this->service->tryFromContents($this->imageBytes(64, 48)));
    }

    #[Test]
    public function try_variants_return_null_on_failure(): void
    {
        $this->assertNull($this->service->tryFromLocal($this->tempPath.'/missing.png'));
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
        $this->assertInstanceOf(Dimensions::class, $this->service->fromContents($this->imageBytes(5, 5)));
    }

    /**
     * Regression: a malformed SVG length such as `1e400` produced a raw
     * InvalidArgumentException from the Dimensions constructor, which is not an
     * ImageDimensionsException and therefore escaped tryFrom*() entirely.
     */
    #[Test]
    public function try_variants_do_not_leak_a_non_package_exception_for_overflowing_svg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1e400" height="10"><rect/></svg>';

        $this->assertNull($this->service->tryFromContents($svg));
    }

    #[Test]
    public function from_contents_reports_an_overflowing_svg_as_a_package_exception(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->service->fromContents('<svg xmlns="http://www.w3.org/2000/svg" width="1e400" height="10"/>');
    }

    // --- size caps on the non-remote sources ---

    /**
     * Regression: fromContents()/fromStream() never consulted max_download_bytes,
     * so an attacker-controlled upload could fill the temp partition.
     */
    #[Test]
    public function from_contents_honours_the_download_cap(): void
    {
        // The cap is never lower than remote_read_bytes (the header probe must
        // fit), so pin both to make the effective limit explicit.
        $service = new ImageDimensionsService([
            'enable_cache' => false,
            'remote_read_bytes' => 8192,
            'max_download_bytes' => 8192,
        ]);

        $this->expectException(FileTooLargeException::class);
        $service->fromContents(str_repeat('J', 20000));
    }

    #[Test]
    public function from_stream_honours_the_download_cap(): void
    {
        $service = new ImageDimensionsService([
            'enable_cache' => false,
            'remote_read_bytes' => 8192,
            'max_download_bytes' => 8192,
        ]);

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, str_repeat('J', 200000));
        rewind($stream);

        try {
            $this->expectException(FileTooLargeException::class);
            $service->fromStream($stream);
        } finally {
            fclose($stream);
        }
    }

    #[Test]
    public function from_stream_still_reads_a_valid_image_within_the_cap(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => false, 'max_download_bytes' => 1048576]);

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $this->imageBytes(90, 45));
        rewind($stream);

        $this->assertDimensions(90, 45, $service->fromStream($stream));
        fclose($stream);
    }

    /**
     * Regression: fromUploadedFile() delegated to fromLocal(), whose cache key is
     * md5(realpath)+filemtime. PHP recycles upload temp names and filemtime has
     * one-second granularity, so two different uploads could collide and return
     * the first upload's dimensions.
     */
    #[Test]
    public function from_uploaded_file_does_not_serve_a_stale_cached_result(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => true, 'cache_ttl' => 3600]);

        // A recycled upload temp path: same name, same mtime, different content.
        $path = $this->tempPath.'/phpRECYCLED';

        file_put_contents($path, $this->imageBytes(10, 10));
        $mtime = filemtime($path);
        $first = $service->fromUploadedFile(new UploadedFile($path, 'a.png', 'image/png', null, true));

        file_put_contents($path, $this->imageBytes(200, 300));
        touch($path, (int) $mtime);
        clearstatcache(true, $path);
        $second = $service->fromUploadedFile(new UploadedFile($path, 'b.png', 'image/png', null, true));

        $this->assertDimensions(10, 10, $first);
        $this->assertDimensions(200, 300, $second);
    }
}
