<?php

namespace Jackardios\ImageDimensions\Tests\Unit;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\UrlAccessException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Files that make getimagesize() allocate far more than a header takes.
 *
 * Running out of memory is a fatal error, so the files that cause it are
 * measured in a process of their own (tests/Support/hostile-file-probe.php).
 */
class HostileFileTest extends TestCase
{
    /** Megabytes; far below what the files below make getimagesize() allocate. */
    private const PROBE_MEMORY_LIMIT = 64;

    protected ImageDimensionsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ImageDimensionsService;
    }

    /**
     * About 100 KB that getimagesize() inflates to 100 MB to find a size.
     */
    #[Test]
    public function it_does_not_inflate_compressed_flash(): void
    {
        $path = $this->tempPath.DIRECTORY_SEPARATOR.'bomb.swf';
        $file = fopen($path, 'wb');
        fwrite($file, "CWS\x0A".pack('V', 100 * 1024 * 1024 + 8));

        // Written in pieces: the test itself must not need that much memory.
        $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        $megabyte = str_repeat("\0", 1024 * 1024);
        for ($written = 0; $written < 100; $written++) {
            fwrite($file, (string) deflate_add($deflate, $megabyte, ZLIB_NO_FLUSH));
        }
        fwrite($file, (string) deflate_add($deflate, '', ZLIB_FINISH));
        fclose($file);

        $this->assertLessThan(200 * 1024, filesize($path));
        $this->assertSame('rejected', $this->probe($path));
    }

    /**
     * With no signature, getimagesize() reads the file as XBM, a line at a
     * time, and a file without a line break is one line.
     *
     * @return array<string, array{0: string}>
     */
    public static function fileWithoutLineBreakProvider(): array
    {
        return [
            'NUL bytes' => ["\0"],
            'text' => ['A'],
        ];
    }

    #[Test]
    #[DataProvider('fileWithoutLineBreakProvider')]
    public function it_does_not_read_a_large_file_without_a_signature_into_memory(string $byte): void
    {
        $this->assertSame('rejected', $this->probe($this->createLargeFile('large.bin', '', $byte, 80)));
    }

    /**
     * An `ftyp` box is a signature only with a brand getimagesize() knows:
     * any other ISO media file is read as XBM too.
     *
     * @return array<string, array{0: string, 1: bool}> The first bytes and whether they are a signature.
     */
    public static function ftypProvider(): array
    {
        $ftyp = static fn (int $size, string $brands): string => pack('N', $size).'ftyp'.$brands;

        return [
            'AVIF as the major brand' => [$ftyp(24, "avif\0\0\0\0mif1miaf"), true],
            'AVIF sequence as the major brand' => [$ftyp(24, "avis\0\0\0\0msf1miaf"), true],
            'AVIF as a compatible brand' => [$ftyp(20, "mif1\0\0\0\0avif"), true],
            'AVIF as the last brand PHP looks at' => [$ftyp(144, str_repeat('abcd', 33).'avif'), true],
            'AVIF past the last brand PHP looks at' => [$ftyp(148, str_repeat('abcd', 34).'avif'), false],
            'AVIF only as the minor version' => [$ftyp(24, 'isomavifisomiso2'), false],
            'AVIF after the end of the box' => [$ftyp(16, "isom\0\0\0\0avif"), false],
            'AVIF across the end of the box' => [$ftyp(19, "isom\0\0\0\0avif"), false],
            'AVIF off the four-byte grid' => [$ftyp(24, "isom\0\0\0\0\0avif\0\0\0"), false],
            'a box too short for a brand list' => [$ftyp(15, "avif\0\0\0"), false],
            'the shortest box with a brand list' => [$ftyp(16, "avif\0\0\0\0"), true],
            'MP4' => [$ftyp(24, "mp42\0\0\0\0mp42isom"), false],
            'a made-up brand' => ['AAAAftypAAAAAAAAAAAA', false],
            // HEIF to PHP 8.5, by the major brand alone; XBM before.
            'HEIC' => [$ftyp(24, "heic\0\0\0\0mif1heic"), defined('IMAGETYPE_HEIF')],
            'HEIF in a box of a brand alone' => [$ftyp(12, 'mif1'), defined('IMAGETYPE_HEIF')],
            'HEIF only as a compatible brand' => [$ftyp(24, "isom\0\0\0\0mif1heic"), false],
            'ftyp cut short' => ["\0\0\0\x18ftypavi", false],
            'ftyp one byte late' => ["\0\0\0\0\x18ftypavif\0\0\0\0avifmif1", false],
            'a box of another type with an AVIF brand' => ["\0\0\0\x18moovavif\0\0\0\0avifmif1", false],
            'no signature' => [str_repeat("\0", 16), false],
        ];
    }

    /**
     * 9 MB that end as XBM text: to getimagesize() the file is a 99x77 image
     * unless its first bytes are a signature.
     */
    #[Test]
    #[DataProvider('ftypProvider')]
    public function it_takes_an_ftyp_box_for_a_signature_only_with_a_brand_php_knows(string $start, bool $isSignature): void
    {
        $path = $this->createLargeFile('large.bin', $start, 'A', 9);
        file_put_contents($path, "\n#define x_width 99\n#define x_height 77\n", FILE_APPEND);

        // PHP 8.1 takes a few more boxes for AVIF than later versions do.
        $isXbm = (((array) @getimagesize($path))[2] ?? null) === IMAGETYPE_XBM;
        if ($isSignature || PHP_VERSION_ID >= 80200) {
            $this->assertSame($isSignature, ! $isXbm, 'getimagesize() does not agree');
        }

        $hasMeasurableFtyp = new \ReflectionMethod(ImageDimensionsService::class, 'hasMeasurableFtyp');
        $this->assertSame($isSignature, $hasMeasurableFtyp->invoke(null, $start));

        // With an AVIF brand it is no image either, but that is for PHP to say.
        try {
            $read = $this->service->fromLocal($path);
        } catch (InvalidImageException) {
            $read = null;
        }
        $this->assertNotSame(['width' => 99, 'height' => 77], $read);
    }

    /**
     * The largest file without a signature that is still read is 8 MB to the
     * byte. Text with two `#define` lines is XBM to getimagesize(), which
     * stops reading at the second.
     */
    #[Test]
    public function it_reads_a_file_without_a_signature_up_to_the_size_limit_and_no_further(): void
    {
        $text = "#define image_width 33\n#define image_height 17\n".str_repeat(str_repeat('A', 1023)."\n", 8192);

        $atLimit = $this->createFile('limit.xbm', substr($text, 0, 8388608));
        $this->assertSame(['width' => 33, 'height' => 17], $this->service->fromLocal($atLimit));

        $overLimit = $this->createFile('over.xbm', substr($text, 0, 8388609));
        $this->assertSame(IMAGETYPE_XBM, ((array) @getimagesize($overLimit))[2] ?? null);

        $this->expectException(InvalidImageException::class);
        $this->service->fromLocal($overLimit);
    }

    /**
     * @return array<string, array{0: string, 1: int}> The bytes and the type getimagesize() takes them for.
     */
    public static function flashProvider(): array
    {
        // getimagesize() reads compressed Flash only if at least 64 bytes
        // of it follow the header, hence a body that does not compress.
        $body = "\x78\x00\x05\x5F\x00\x00\x0F\xA0\x00\x00\x0C\x01\x00".hash('sha512', 'flash', true).hash('sha512', 'body', true);
        $header = "\x0A".pack('V', strlen($body) + 8);

        return [
            'Flash' => ['FWS'.$header.$body, IMAGETYPE_SWF],
            'compressed Flash' => ['CWS'.$header.gzcompress($body), IMAGETYPE_SWC],
        ];
    }

    #[Test]
    #[DataProvider('flashProvider')]
    public function it_does_not_take_flash_for_an_image(string $bytes, int $type): void
    {
        // What the guard is for: getimagesize() reports 550x400 here.
        $this->assertSame([550, 400, $type], array_slice((array) @getimagesizefromstring($bytes), 0, 3));

        $url = 'https://example.com/movie.swf';
        // A callback: the body is requested again when its header was not enough.
        Http::fake([$url => fn () => Http::response(Utils::streamFor($bytes), 200)]);
        $this->useInMemoryDisk('remote')->put('movie.swf', $bytes);

        $sources = [
            'local' => fn () => $this->service->fromLocal($this->createFile('movie.swf', $bytes)),
            'url' => fn () => $this->service->fromUrl($url),
            'storage' => fn () => $this->service->fromStorage('remote', 'movie.swf'),
        ];

        foreach ($sources as $source => $measure) {
            try {
                $size = $measure();
                $this->fail("{$source}: read as {$size['width']}x{$size['height']}.");
            } catch (InvalidImageException $e) {
                $this->assertStringContainsString('Could not determine image dimensions', $e->getMessage(), $source);
            } catch (UrlAccessException $e) {
                // fromUrl() reports an image it cannot read as a URL it cannot open.
                $this->assertSame('url', $source);
                $this->assertInstanceOf(InvalidImageException::class, $e->getPrevious());
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function oversizedProvider(): array
    {
        $png = static fn (int $width, int $height): string => "\x89PNG\r\n\x1A\n".pack('N', 13).'IHDR'.pack('NN', $width, $height)."\x08\x02\x00\x00\x00".pack('N', 0);

        return [
            // getimagesize() reports 4294967295x4294967295.
            'XBM text with a huge size' => ["#define x_width 99999999999999999999\n#define x_height 99999999999999999999\n"],
            // getimagesize() reports 4294967291x4294967289.
            'XBM text with a negative size' => ["#define x_width -5\n#define x_height -7\n"],
            'PNG one pixel too wide' => [$png(2147483648, 17)],
            'PNG one pixel too high' => [$png(33, 2147483648)],
        ];
    }

    #[Test]
    #[DataProvider('oversizedProvider')]
    public function it_rejects_sizes_beyond_a_signed_32_bit_integer(string $bytes): void
    {
        $this->assertGreaterThan(2147483647, max(array_slice((array) @getimagesizefromstring($bytes), 0, 2)));

        $this->expectException(InvalidImageException::class);
        $this->service->fromLocal($this->createFile('huge.bin', $bytes));
    }

    #[Test]
    public function it_reads_the_largest_size(): void
    {
        $png = "\x89PNG\r\n\x1A\n".pack('N', 13).'IHDR'.pack('NN', 2147483647, 2147483647)."\x08\x02\x00\x00\x00".pack('N', 0);

        $this->assertSame(['width' => 2147483647, 'height' => 2147483647], $this->service->fromLocal($this->createFile('largest.png', $png)));
    }

    #[Test]
    public function it_still_reads_large_images_with_a_signature(): void
    {
        $formats = [];
        foreach (['gif', 'jpeg', 'png', 'bmp', 'webp'] as $format) {
            $formats[$format] = (string) file_get_contents($this->createImage("small.{$format}", 33, 17, $format));
        }

        // PHP 8.1 knows AVIF, but not its size.
        if (function_exists('imageavif') && PHP_VERSION_ID >= 80200) {
            ob_start();
            imageavif(imagecreatetruecolor(33, 17));
            $avif = (string) ob_get_clean();
            // With `avif` as the last of the brands PHP looks at.
            $formats['avif'] = pack('N', 144).'ftyp'.str_repeat('abcd', 33).'avif'.substr($avif, unpack('N', $avif)[1]);
        }

        foreach ($formats as $format => $bytes) {
            $this->assertSame(
                ['width' => 33, 'height' => 17],
                $this->service->fromLocal($this->createLargeFile("large.{$format}", $bytes, "\0", 9)),
                $format
            );
        }
    }

    #[Test]
    public function it_still_reads_small_images_without_a_signature(): void
    {
        $image = imagecreate(128, 64);
        imagecolorallocate($image, 255, 255, 255);
        ob_start();
        imagewbmp($image);

        $this->assertSame(['width' => 128, 'height' => 64], $this->service->fromLocal($this->createFile('image.wbmp', (string) ob_get_clean())));
    }

    private function createLargeFile(string $filename, string $start, string $byte, int $megabytes): string
    {
        $path = $this->tempPath.DIRECTORY_SEPARATOR.$filename;
        $file = fopen($path, 'wb');
        fwrite($file, $start);
        for ($written = 0; $written < $megabytes; $written++) {
            fwrite($file, str_repeat($byte, 1024 * 1024));
        }
        fclose($file);

        return $path;
    }

    /**
     * What the probe printed: "rejected", "read as WxH", or the fatal error
     * of a process that ran out of memory.
     */
    private function probe(string $path): string
    {
        $process = proc_open(
            [
                PHP_BINARY,
                '-d', 'memory_limit='.self::PROBE_MEMORY_LIMIT.'M',
                '-d', 'display_errors=stderr',
                dirname(__DIR__).DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'hostile-file-probe.php',
                dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php',
                $path,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return trim($output.$errors);
    }
}
