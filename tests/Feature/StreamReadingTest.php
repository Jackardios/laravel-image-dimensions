<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Jackardios\ImageDimensions\Exceptions\FileTooLargeException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\Support\CountingStream;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Streams (fromStream(), non-local disks) and fromContents() read only as
 * much as they need, and touch the disk only when the header is not enough.
 */
class StreamReadingTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $config
     */
    private function service(array $config = []): ImageDimensionsService
    {
        return new ImageDimensionsService(array_replace_recursive(['enable_cache' => false], $config));
    }

    /**
     * A temp_dir that does not exist: any temporary file would fail.
     */
    private function serviceWithoutTempDir(): ImageDimensionsService
    {
        return $this->service(['temp_dir' => $this->tempPath.DIRECTORY_SEPARATOR.'missing']);
    }

    #[Test]
    public function contents_are_analyzed_in_memory(): void
    {
        $service = $this->serviceWithoutTempDir();

        $this->assertDimensions(30, 20, $service->fromContents($this->imageBytes(30, 20)));
        $this->assertDimensions(12, 34, $service->fromContents('<svg xmlns="http://www.w3.org/2000/svg" width="12" height="34"/>'));

        $this->expectException(InvalidImageException::class);
        $service->fromContents('no image');
    }

    #[Test]
    public function a_header_that_gives_the_dimensions_needs_no_temporary_file(): void
    {
        $service = $this->serviceWithoutTempDir();
        $this->useInMemoryDisk('mem')->put('a.png', $this->imageBytes(30, 20));

        $this->assertDimensions(30, 20, $service->fromStream(CountingStream::open($this->imageBytes(30, 20))));
        $this->assertDimensions(30, 20, $service->fromStorage('mem', 'a.png'));
    }

    #[Test]
    public function an_empty_stream_is_reported_as_empty(): void
    {
        $this->useInMemoryDisk('mem')->put('empty.png', '');

        foreach ([fn () => $this->service()->fromStream(CountingStream::open('')), fn () => $this->service()->fromStorage('mem', 'empty.png')] as $read) {
            try {
                $read();
                $this->fail('Expected an InvalidImageException.');
            } catch (InvalidImageException $e) {
                $this->assertStringContainsString('File is empty', $e->getMessage());
            }
        }
    }

    /**
     * Markup goes to the package's SVG parser, never to getimagesize(),
     * which reads SVG itself on PHP 8.5 but ignores units (2x1 here).
     */
    #[Test]
    public function an_svg_stream_is_measured_by_the_svg_parser(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="2in" height="1in"/>';

        $this->assertDimensions(192, 96, $this->service()->fromStream(CountingStream::open($svg)));
    }

    #[Test]
    public function a_short_stream_that_is_no_image_needs_no_temporary_file(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Could not determine image dimensions');
        $this->serviceWithoutTempDir()->fromStream(CountingStream::open('no image'));
    }

    #[Test]
    public function the_rest_of_a_stream_is_not_read_once_the_header_suffices(): void
    {
        $stream = CountingStream::open($this->imageBytes(30, 20).str_repeat("\0", 10000000));

        $this->assertDimensions(30, 20, $this->service(['remote_read_bytes' => 65536])->fromStream($stream));
        // Plus what PHP reads ahead into its buffer.
        $this->assertLessThanOrEqual(65536 + 8192, CountingStream::bytesRead($stream));
    }

    /**
     * An SVG document used to be read up to the download cap (32 MB by
     * default) and only then rejected by its own, smaller cap.
     */
    #[Test]
    public function an_svg_is_read_no_further_than_its_own_cap(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><!--'.str_repeat('x', 5000000).'--></svg>';
        $stream = CountingStream::open($svg);

        try {
            $this->service(['svg' => ['max_file_size' => 200000]])->fromStream($stream);
            $this->fail('Expected a FileTooLargeException.');
        } catch (FileTooLargeException $e) {
            $this->assertStringContainsString('SVG file is too large (max 200000 bytes)', $e->getMessage());
        }

        $this->assertLessThanOrEqual(200001 + 8192, CountingStream::bytesRead($stream));
    }

    #[Test]
    public function an_svg_is_read_no_further_than_the_download_cap_when_that_is_smaller(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><!--'.str_repeat('x', 5000000).'--></svg>';
        $stream = CountingStream::open($svg);

        try {
            $this->service(['max_download_bytes' => 300000])->fromStream($stream);
            $this->fail('Expected a FileTooLargeException.');
        } catch (FileTooLargeException $e) {
            $this->assertStringContainsString('max 300000 bytes', $e->getMessage());
            $this->assertStringNotContainsString('SVG', $e->getMessage());
        }

        $this->assertLessThanOrEqual(300001 + 8192, CountingStream::bytesRead($stream));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int, 2: string|null}>
     */
    public static function svgCapProvider(): array
    {
        return [
            'at the SVG cap' => [['svg' => ['max_file_size' => 20000]], 20000, null],
            'past the SVG cap' => [['svg' => ['max_file_size' => 20000]], 20001, 'SVG file is too large (max 20000 bytes)'],
            'at the download cap' => [['max_download_bytes' => 20000], 20000, null],
            'past the download cap' => [['max_download_bytes' => 20000], 20001, 'too large to download (max 20000 bytes)'],
            // Either message would do; the SVG one names the stricter setting.
            'past both, equal caps' => [['max_download_bytes' => 20000, 'svg' => ['max_file_size' => 20000]], 20001, 'SVG file is too large (max 20000 bytes)'],
            'past the download cap, no SVG cap' => [['max_download_bytes' => 20000, 'svg' => ['max_file_size' => 0]], 20001, 'too large to download (max 20000 bytes)'],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[Test]
    #[DataProvider('svgCapProvider')]
    public function an_svg_stream_is_held_to_the_stricter_cap(array $config, int $length, ?string $error): void
    {
        $service = $this->service(['remote_read_bytes' => 8192] + $config);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="34"><!--';
        $svg .= str_repeat('x', $length - strlen($svg) - 9).'--></svg>';
        $this->assertSame($length, strlen($svg));

        if ($error !== null) {
            $this->expectException(FileTooLargeException::class);
            $this->expectExceptionMessage($error);
        }

        $this->assertDimensions(12, 34, $service->fromStream(CountingStream::open($svg)));
    }

    /**
     * A stream that has nothing to deliver yet has not ended: it is waited
     * for, as a socket or a pipe would be.
     */
    #[Test]
    public function a_stream_that_falls_silent_for_a_moment_is_waited_for(): void
    {
        $stream = CountingStream::open($this->imageBytes(30, 20), silentFor: 0.05);

        $this->assertDimensions(30, 20, $this->service()->fromStream($stream));
    }

    #[Test]
    public function a_stream_that_stays_silent_is_given_up_on(): void
    {
        $stream = CountingStream::open($this->imageBytes(30, 20), silentFor: INF);
        $started = microtime(true);

        try {
            $this->service()->fromStream($stream);
            $this->fail('Expected an InvalidImageException.');
        } catch (InvalidImageException $e) {
            $this->assertStringContainsString('File is empty', $e->getMessage());
        }

        // About 250 ms of retries.
        $this->assertLessThan(5.0, microtime(true) - $started);
    }

    #[Test]
    public function an_svg_past_the_header_is_read_whole(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="34"><!--'.str_repeat('x', 500000).'--></svg>';

        $this->assertDimensions(12, 34, $this->service()->fromStream(CountingStream::open($svg)));
    }

    /**
     * The frame header of this JPEG comes after 260 KB of metadata, past
     * remote_read_bytes: the stream is read on into a temporary file.
     */
    #[Test]
    public function a_raster_image_whose_header_comes_late_is_read_on(): void
    {
        $jpeg = $this->jpegWithLargeMetadata(40, 30);
        $this->useInMemoryDisk('mem')->put('late.jpg', $jpeg);
        $service = $this->service(['temp_dir' => $this->tempPath]);

        $this->assertDimensions(40, 30, $service->fromStream(CountingStream::open($jpeg)));
        $this->assertDimensions(40, 30, $service->fromStorage('mem', 'late.jpg'));
        $this->assertDimensions(40, 30, $service->fromContents($jpeg));
        $this->assertSame([], glob($this->tempPath.DIRECTORY_SEPARATOR.'imgdim_*'));
    }

    /**
     * Without a download cap a stream is read to its end, past the 1 MB a
     * single read takes.
     */
    #[Test]
    public function without_a_download_cap_a_stream_is_read_to_its_end(): void
    {
        $jpeg = $this->jpegWithLargeMetadata(40, 30, 40);
        $this->assertGreaterThan(2 * 1048576, strlen($jpeg));
        $this->useInMemoryDisk('mem')->put('late.jpg', $jpeg);
        $service = $this->service(['max_download_bytes' => 0]);

        $this->assertDimensions(40, 30, $service->fromStream(CountingStream::open($jpeg)));
        $this->assertDimensions(40, 30, $service->fromStorage('mem', 'late.jpg'));
    }

    #[Test]
    public function without_any_cap_an_svg_is_read_to_its_end(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="34"><!--'.str_repeat('x', 3000000).'--></svg>';
        $service = $this->service(['max_download_bytes' => 0, 'svg' => ['max_file_size' => 0]]);

        $this->assertDimensions(12, 34, $service->fromStream(CountingStream::open($svg)));
    }

    #[Test]
    public function reading_on_stops_at_the_download_cap(): void
    {
        $stream = CountingStream::open(str_repeat('J', 5000000));

        try {
            $this->service(['max_download_bytes' => 400000])->fromStream($stream);
            $this->fail('Expected a FileTooLargeException.');
        } catch (FileTooLargeException $e) {
            $this->assertStringContainsString('max 400000 bytes', $e->getMessage());
        }

        $this->assertLessThanOrEqual(400001 + 8192, CountingStream::bytesRead($stream));
    }
}
