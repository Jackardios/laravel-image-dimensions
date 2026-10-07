<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Jackardios\ImageDimensions\Dimensions;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Formats getimagesize() gets wrong: HEIF (unreadable before PHP 8.5, the
 * coded size since), WBMP and XBM (no signature, so found in random bytes
 * and in text) and Flash (not an image). And the formats it gets right.
 */
class ImageFormatTest extends TestCase
{
    private ImageDimensionsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ImageDimensionsService([
            'enable_cache' => false,
            'url' => ['allow_private_hosts' => true],
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function heifProvider(): array
    {
        return [
            // Coded as 64x64; PHP 8.5's getimagesize() reports that.
            'cropped' => ['p33x17.heic', 33, 17],
            'grid' => ['grid1000.heic', 1000, 700],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'local' => ['local'],
            'contents' => ['contents'],
            'stream' => ['stream'],
            'uploaded file' => ['uploaded file'],
            'local disk' => ['local disk'],
            'other disk' => ['other disk'],
            'url' => ['url'],
        ];
    }

    #[Test]
    #[DataProvider('sourceProvider')]
    public function it_reads_heif_from_every_source(string $source): void
    {
        foreach (self::heifProvider() as [$fixture, $width, $height]) {
            $this->assertDimensions($width, $height, $this->measure($source, self::fixtureBytes($fixture), 'photo.heic'));
        }
    }

    /**
     * The metadata may come after the first chunk read from a stream.
     */
    #[Test]
    #[DataProvider('sourceProvider')]
    public function it_reads_heif_whose_metadata_is_not_at_the_start(string $source): void
    {
        $bytes = self::padAfterFtyp(self::fixtureBytes('p33x17.heic'), 100000);

        $this->assertDimensions(33, 17, $this->measure($source, $bytes, 'photo.heic'));
    }

    /**
     * The first chunk read holds only part of the metadata. PHP 8.5 finds
     * the coded size (64x64) in it; the crop comes later.
     */
    #[Test]
    #[DataProvider('sourceProvider')]
    public function it_reads_heif_whose_metadata_runs_past_the_first_read(string $source): void
    {
        $bytes = self::padMeta(self::fixtureBytes('p33x17.heic'), 200000);

        $this->assertDimensions(33, 17, $this->measure($source, $bytes, 'photo.heic'));
    }

    #[Test]
    public function heif_metadata_the_reader_rejects_falls_back_to_php(): void
    {
        // The `meta` box claims to run past the end of the file.
        $bytes = substr_replace(self::fixtureBytes('grid1000.heic'), "\x01", 28, 1);

        foreach (['contents', 'stream'] as $source) {
            try {
                $this->assertDimensions(1000, 700, $this->measure($source, $bytes, 'photo.heic'));
                $this->assertGreaterThanOrEqual(80500, PHP_VERSION_ID, $source);
            } catch (InvalidImageException $e) {
                $this->assertLessThan(80500, PHP_VERSION_ID, "{$source}: {$e->getMessage()}");
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function wbmpLikeProvider(): array
    {
        return [
            // getimagesize() reports 128x64 for these.
            'bytes starting with two NULs' => ["\x00\x00\x81\x00\x40".str_repeat("\x00", 100)],
            'a real WBMP image' => [self::wbmp(128, 64)],
        ];
    }

    #[Test]
    #[DataProvider('wbmpLikeProvider')]
    public function it_does_not_take_bytes_for_a_wbmp_image(string $bytes): void
    {
        foreach (array_keys(self::sourceProvider()) as $source) {
            try {
                $dimensions = $this->measure($source, $bytes, 'image.wbmp');
                $this->fail("{$source}: read as {$dimensions->width}x{$dimensions->height}.");
            } catch (InvalidImageException $e) {
                $this->assertStringContainsString('Could not determine image dimensions', $e->getMessage(), $source);
            }
        }
    }

    /**
     * @return array<string, array{0: string, 1: int}> The bytes and the type getimagesize() takes them for.
     */
    public static function notAnImageProvider(): array
    {
        // getimagesize() reads compressed Flash only if at least 64 bytes
        // of it follow the header, hence a body that does not compress.
        $flashBody = "\x78\x00\x05\x5F\x00\x00\x0F\xA0\x00\x00\x0C\x01\x00".hash('sha512', 'flash', true).hash('sha512', 'body', true);
        $flashHeader = "\x0A".pack('V', strlen($flashBody) + 8);
        $xbm = "\n#define x_width 99999999\n#define x_height 99999999\n";

        return [
            'Flash' => ['FWS'.$flashHeader.$flashBody, IMAGETYPE_SWF],
            'compressed Flash' => ['CWS'.$flashHeader.gzcompress($flashBody), IMAGETYPE_SWC],
            // PHP compares the first three bytes before it looks for an ftyp box.
            'Flash with an AVIF ftyp box' => ["FWS\x0Aftypavif\0\0\0\0mif1miaf".str_repeat("\x55", 64), IMAGETYPE_SWF],
            'text that reads as XBM' => [$xbm, IMAGETYPE_XBM],
            'an MP4 file that reads as XBM' => ["\0\0\0\x18ftypmp42\0\0\0\0mp42isom".$xbm, IMAGETYPE_XBM],
            'an HEIF-like file with an AVIF brand outside the ftyp box' => ["\0\0\0\x10ftypmp42\0\0\0\0avif".$xbm, IMAGETYPE_XBM],
        ];
    }

    #[Test]
    #[DataProvider('notAnImageProvider')]
    public function it_does_not_take_flash_or_text_for_an_image(string $bytes, int $type): void
    {
        // What the guard is for: getimagesize() does report a size here.
        $this->assertSame($type, ((array) @getimagesizefromstring($bytes))[2] ?? null);

        foreach (['local', 'contents', 'stream', 'uploaded file', 'local disk', 'other disk', 'url'] as $source) {
            try {
                $dimensions = $this->measure($source, $bytes, 'file.bin');
                $this->fail("{$source}: read as {$dimensions->width}x{$dimensions->height}.");
            } catch (InvalidImageException $e) {
                $this->assertStringContainsString('Could not determine image dimensions', $e->getMessage(), $source);
            }
        }

        $this->assertNull($this->service->tryFromContents($bytes));
    }

    /**
     * Every format getimagesize() knows by a signature is still read.
     *
     * @return array<string, array{0: string}>
     */
    public static function signedFormatProvider(): array
    {
        $image = imagecreatetruecolor(33, 17);
        $encode = static function (callable $write) use ($image): string {
            ob_start();
            $write($image);

            return (string) ob_get_clean();
        };

        return [
            'GIF' => [$encode(imagegif(...))],
            'JPEG' => [$encode(imagejpeg(...))],
            'PNG' => [$encode(imagepng(...))],
            'BMP' => [$encode(imagebmp(...))],
            'WebP' => [$encode(imagewebp(...))],
            'PSD' => ["8BPS\x00\x01".str_repeat("\0", 6).pack('nNNnn', 3, 17, 33, 8, 3)],
            'ICO' => ["\x00\x00\x01\x00\x01\x00".chr(33).chr(17)."\x00\x00\x01\x00\x20\x00".pack('VV', 100, 22)],
            'TIFF, little-endian' => ["II\x2A\x00".pack('Vv', 8, 2).pack('vvVV', 256, 4, 1, 33).pack('vvVV', 257, 4, 1, 17).pack('V', 0)],
            'TIFF, big-endian' => ["MM\x00\x2A".pack('Nn', 8, 2).pack('nnNN', 256, 4, 1, 33).pack('nnNN', 257, 4, 1, 17).pack('N', 0)],
            'JPEG 2000 codestream' => ["\xFF\x4F\xFF\x51".pack('nnNN', 41, 0, 33, 17).str_repeat("\0", 24).pack('n', 3).str_repeat("\x07\x01\x01", 3)],
            'JPEG 2000 (JP2)' => ["\x00\x00\x00\x0CjP  \r\n\x87\n".pack('N', 20)."ftypjp2 \0\0\0\0jp2 ".pack('N', 57).'jp2c'."\xFF\x4F\xFF\x51".pack('nnNN', 41, 0, 33, 17).str_repeat("\0", 24).pack('n', 3).str_repeat("\x07\x01\x01", 3)],
            'IFF' => ['FORM'.pack('N', 40).'ILBMBMHD'.pack('Nnn', 20, 33, 17).str_repeat("\0", 4).chr(8).str_repeat("\0", 11)],
        ];
    }

    /**
     * A header can name any 32-bit size; SVG and HEIF sizes have the same limit.
     *
     * @return array<string, array{0: string}>
     */
    public static function oversizedRasterProvider(): array
    {
        $png = static fn (int $width, int $height): string => "\x89PNG\r\n\x1A\n".pack('N', 13).'IHDR'.pack('NN', $width, $height)."\x08\x02\x00\x00\x00".pack('N', 0);
        $bmp = substr_replace((static function (): string {
            ob_start();
            imagebmp(imagecreatetruecolor(33, 17));

            return (string) ob_get_clean();
        })(), pack('V', 0xFFFFFFDF), 18, 4);

        return [
            'PNG of 4294967295x4294967295' => [$png(4294967295, 4294967295)],
            'PNG one pixel too wide' => [$png(2147483648, 17)],
            'PNG one pixel too high' => [$png(33, 2147483648)],
            // getimagesize() reports 4294967263x17.
            'BMP with a width of -33' => [$bmp],
        ];
    }

    #[Test]
    #[DataProvider('oversizedRasterProvider')]
    public function it_rejects_raster_sizes_beyond_a_signed_32_bit_integer(string $bytes): void
    {
        $this->assertGreaterThan(2147483647, max(array_slice((array) getimagesizefromstring($bytes), 0, 2)));

        foreach (['local', 'contents', 'stream', 'url'] as $source) {
            try {
                $dimensions = $this->measure($source, $bytes, 'file.bin');
                $this->fail("{$source}: read as {$dimensions->width}x{$dimensions->height}.");
            } catch (InvalidImageException $e) {
                $this->assertStringContainsString('Could not determine image dimensions', $e->getMessage(), $source);
            }
        }
    }

    #[Test]
    public function it_reads_the_largest_raster_size(): void
    {
        $png = "\x89PNG\r\n\x1A\n".pack('N', 13).'IHDR'.pack('NN', 2147483647, 2147483647)."\x08\x02\x00\x00\x00".pack('N', 0);

        $this->assertDimensions(2147483647, 2147483647, $this->service->fromContents($png));
    }

    #[Test]
    #[DataProvider('signedFormatProvider')]
    public function it_reads_every_format_with_a_signature(string $bytes): void
    {
        foreach (['local', 'contents', 'stream', 'url'] as $source) {
            $this->assertDimensions(33, 17, $this->measure($source, $bytes, 'file.bin'));
        }
    }

    /**
     * AVIF is the one `ftyp` format getimagesize() reads in every supported
     * PHP version; the HEIF reader does not take it.
     */
    #[Test]
    public function it_reads_avif(): void
    {
        if (! function_exists('imageavif')) {
            $this->markTestSkipped('GD is built without AVIF support.');
        }

        ob_start();
        imageavif(imagecreatetruecolor(33, 17));
        $avif = (string) ob_get_clean();

        foreach (['local', 'contents', 'stream', 'url'] as $source) {
            $this->assertDimensions(33, 17, $this->measure($source, $avif, 'file.bin'));
        }
    }

    private function measure(string $source, string $bytes, string $name): Dimensions
    {
        return match ($source) {
            'local' => $this->service->fromLocal($this->createFile($name, $bytes)),
            'contents' => $this->service->fromContents($bytes),
            'stream' => $this->service->fromStream($this->memoryStream($bytes)),
            'uploaded file' => $this->service->fromUploadedFile(new UploadedFile($this->createFile('upload.bin', $bytes), $name, null, null, true)),
            'local disk' => $this->fromLocalDisk($name, $bytes),
            'other disk' => $this->fromOtherDisk($name, $bytes),
            'url' => $this->fromFakeUrl($bytes),
        };
    }

    private function fromLocalDisk(string $name, string $bytes): Dimensions
    {
        config(['filesystems.disks.photos' => ['driver' => 'local', 'root' => $this->tempPath]]);
        $this->createFile($name, $bytes);

        return $this->service->fromStorage('photos', $name);
    }

    private function fromOtherDisk(string $name, string $bytes): Dimensions
    {
        $this->useInMemoryDisk('mem')->put($name, $bytes);

        return $this->service->fromStorage('mem', $name);
    }

    private function fromFakeUrl(string $bytes): Dimensions
    {
        // Fakes add up and the first match wins, so each gets its own URL.
        $url = 'https://example.com/'.md5($bytes);
        Http::fake([$url => Http::response(Utils::streamFor($bytes), 200)]);

        return $this->service->fromUrl($url);
    }

    /**
     * @return resource
     */
    private function memoryStream(string $bytes)
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    private static function fixtureBytes(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.$name);
    }

    /**
     * Insert a `free` box after `ftyp`, pushing the metadata back.
     */
    private static function padAfterFtyp(string $bytes, int $padding): string
    {
        $ftypSize = unpack('N', $bytes)[1];

        return substr($bytes, 0, $ftypSize).pack('N', 8 + $padding).'free'.str_repeat("\0", $padding).substr($bytes, $ftypSize);
    }

    /**
     * Append a `free` box to the `meta` box that follows `ftyp`.
     */
    private static function padMeta(string $bytes, int $padding): string
    {
        $ftypSize = unpack('N', $bytes)[1];
        $metaSize = unpack('N', $bytes, $ftypSize)[1];

        return substr($bytes, 0, $ftypSize).pack('N', $metaSize + 8 + $padding)
            .substr($bytes, $ftypSize + 4, $metaSize - 4).pack('N', 8 + $padding).'free'.str_repeat("\0", $padding)
            .substr($bytes, $ftypSize + $metaSize);
    }

    private static function wbmp(int $width, int $height): string
    {
        $image = imagecreate($width, $height);
        imagecolorallocate($image, 255, 255, 255);
        ob_start();
        imagewbmp($image);

        return (string) ob_get_clean();
    }
}
