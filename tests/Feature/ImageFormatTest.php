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
 * coded size since) and WBMP (no signature, so found in random bytes).
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

    #[Test]
    public function heif_metadata_the_reader_rejects_falls_back_to_php(): void
    {
        // The `meta` box claims to run past the end of the file.
        $bytes = substr_replace(self::fixtureBytes('grid1000.heic'), "\x01", 28, 1);

        if (PHP_VERSION_ID < 80500) {
            $this->expectException(InvalidImageException::class);
        }

        $this->assertDimensions(1000, 700, $this->service->fromContents($bytes));
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

    private static function wbmp(int $width, int $height): string
    {
        $image = imagecreate($width, $height);
        imagecolorallocate($image, 255, 255, 255);
        ob_start();
        imagewbmp($image);

        return (string) ob_get_clean();
    }
}
