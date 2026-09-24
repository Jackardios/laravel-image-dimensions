<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\Exceptions\FileTooLargeException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SplFileInfo;

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

    #[Test]
    public function it_reads_a_seekable_stream_from_its_start(): void
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $this->imageBytes(30, 90));
        fseek($stream, 10);

        $this->assertDimensions(30, 90, $this->service->fromStream($stream));
        $this->assertSame(10, ftell($stream), 'The position must be restored.');
    }

    #[Test]
    public function it_restores_the_position_of_a_stream_that_is_no_image(): void
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, str_repeat('J', 1000));
        fseek($stream, 10);

        $this->assertNull($this->service->tryFromStream($stream));
        $this->assertSame(10, ftell($stream));
    }

    #[Test]
    public function it_reads_a_stream_that_cannot_seek_from_where_it_is(): void
    {
        $pair = stream_socket_pair(PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$reader, $writer] = $pair;

        fwrite($writer, 'skip'.$this->imageBytes(30, 90));
        fclose($writer);
        $this->assertSame('skip', fread($reader, 4));
        $this->assertFalse(stream_get_meta_data($reader)['seekable']);

        $this->assertDimensions(30, 90, $this->service->fromStream($reader));
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

    /**
     * Messages name an upload by its client name, as they do once the file
     * has been read: the server's temporary path means nothing to the user.
     */
    #[Test]
    public function an_unreadable_upload_is_named_by_its_client_name(): void
    {
        $path = $this->createImage('phpA1b2C3', 10, 10);
        $this->makeUnreadable($path);

        try {
            $this->service->fromUploadedFile(new UploadedFile($path, 'holiday.png', 'image/png', null, true));
            $this->fail('Expected an InvalidImageException.');
        } catch (InvalidImageException $e) {
            $this->assertSame('File is not readable: holiday.png', $e->getMessage());
        }
    }

    #[Test]
    public function a_vanished_upload_is_named_by_its_client_name(): void
    {
        $path = $this->createImage('phpA1b2C3', 10, 10);
        $upload = new UploadedFile($path, 'holiday.png', 'image/png', null, true);
        unlink($path);

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('Local file not found: holiday.png');
        $this->service->fromUploadedFile($upload);
    }

    #[Test]
    public function it_reads_a_file_that_is_no_upload(): void
    {
        $this->assertDimensions(12, 34, $this->service->fromUploadedFile(new SplFileInfo($this->createImage('a.png', 12, 34))));
    }

    #[Test]
    public function a_missing_upload_is_not_found(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->service->fromUploadedFile(new SplFileInfo($this->tempPath.'/missing.png'));
    }

    // --- tryFrom* ---

    #[Test]
    public function try_variants_return_dimensions_on_success(): void
    {
        $path = $this->createImage('ok.png', 12, 34);
        $this->useInMemoryDisk('mem')->put('ok.png', $this->imageBytes(12, 34));
        Http::fake(['https://example.com/ok.png' => Http::response($this->imageBytes(12, 34))]);
        $stream = fopen($path, 'rb');

        $this->assertDimensions(12, 34, $this->service->tryFromLocal($path));
        $this->assertDimensions(12, 34, $this->service->tryFromUrl('https://example.com/ok.png'));
        $this->assertDimensions(12, 34, $this->service->tryFromStorage('mem', 'ok.png'));
        $this->assertDimensions(12, 34, $this->service->tryFromContents($this->imageBytes(12, 34)));
        $this->assertDimensions(12, 34, $this->service->tryFromStream($stream));
        $this->assertDimensions(12, 34, $this->service->tryFromUploadedFile(new UploadedFile($path, 'ok.png', 'image/png', null, true)));

        fclose($stream);
    }

    #[Test]
    public function try_variants_return_null_on_failure(): void
    {
        $path = $this->createFile('garbage.png', 'garbage');
        $this->useInMemoryDisk('mem')->put('garbage.png', 'garbage');
        Http::fake(['https://example.com/garbage.png' => Http::response('garbage')]);
        $stream = fopen($path, 'rb');

        $this->assertNull($this->service->tryFromLocal($this->tempPath.'/missing.png'));
        $this->assertNull($this->service->tryFromUrl('https://example.com/garbage.png'));
        $this->assertNull($this->service->tryFromStorage('mem', 'garbage.png'));
        $this->assertNull($this->service->tryFromStorage('nonexistent-disk', 'x.png'));
        $this->assertNull($this->service->tryFromContents('not an image'));
        $this->assertNull($this->service->tryFromStream($stream));
        $this->assertNull($this->service->tryFromUploadedFile(new UploadedFile($path, 'garbage.png', 'image/png', null, true)));

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
