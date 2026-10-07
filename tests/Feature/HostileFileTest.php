<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\Support\LocalHttpServer;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Files that make getimagesize() allocate far more than they hold: only
 * content that getimagesize() itself recognises by a signature may reach it.
 *
 * Running out of memory is a fatal error, so the files that cause it are
 * measured in a process of their own (tests/Support/hostile-file-probe.php).
 */
class HostileFileTest extends TestCase
{
    /** Megabytes; far below what the files below make getimagesize() allocate. */
    private const PROBE_MEMORY_LIMIT = 64;

    /** What getimagesize() reads without a signature, or inflates. */
    private const UNSAFE_TYPES = [IMAGETYPE_WBMP, IMAGETYPE_XBM, IMAGETYPE_SWF, IMAGETYPE_SWC];

    private static LocalHttpServer $server;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$server = LocalHttpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();

        parent::tearDownAfterClass();
    }

    /**
     * One way in for every place that calls getimagesize(): a file, bytes, a
     * stream, and the header of a download. Then compressed Flash dressed as
     * AVIF: PHP compares the first three bytes with `CWS` before it looks for
     * an `ftyp` box, so an AVIF brand after them does not make it an image.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function compressedFlashProvider(): array
    {
        return [
            'fromLocal' => ['fromLocal', false],
            'fromContents' => ['fromContents', false],
            'fromStream' => ['fromStream', false],
            'fromUrl' => ['fromUrl', false],
            'with an AVIF ftyp box' => ['fromLocal', true],
        ];
    }

    /**
     * About 150 KB that getimagesize() inflates to 150 MB to find a size. It
     * is longer than remote_read_bytes, so a URL has it measured twice: the
     * header during the download, then the whole file.
     */
    #[Test]
    #[DataProvider('compressedFlashProvider')]
    public function it_does_not_inflate_compressed_flash(string $method, bool $withFtyp): void
    {
        $path = $this->createCompressedFlash(150, $withFtyp);

        $this->assertGreaterThan(131072, filesize($path));
        $this->assertLessThan(200 * 1024, filesize($path));
        $this->assertSame('rejected', $this->probe($method, $path));
    }

    /**
     * With no signature, getimagesize() reads the file as XBM, a line at a
     * time, and a file without a line break is one line.
     *
     * An `ftyp` box is a signature only with a brand getimagesize() knows:
     * any other ISO media file is read as XBM too.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fileWithoutLineBreakProvider(): array
    {
        return [
            'NUL bytes' => ['', "\0"],
            'text' => ['', 'A'],
            'an ftyp box of an unknown brand' => ['AAAAftypAAAA', 'A'],
            'an MP4 file' => ["\0\0\0\x18ftypmp42\0\0\0\0mp42isom", 'A'],
        ];
    }

    #[Test]
    #[DataProvider('fileWithoutLineBreakProvider')]
    public function it_does_not_read_a_large_file_without_a_signature_into_memory(string $start, string $byte): void
    {
        $path = $this->createLargeFile($start, $byte, 80);

        $this->assertSame('rejected', $this->probe('fromLocal', $path));
    }

    /**
     * A stream is read into a temporary file up to max_download_bytes (32 MB
     * by default) and measured there, so a URL or a remote disk can deliver
     * such a file as well.
     */
    #[Test]
    #[DataProvider('remoteMethodProvider')]
    public function it_does_not_read_a_large_download_without_a_signature_into_memory(string $method, string $start): void
    {
        // Text, not NUL bytes: getimagesize() then allocates twice the size.
        $path = $this->createLargeFile($start, 'A', 31);

        $this->assertSame('rejected', $this->probe($method, $path));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function remoteMethodProvider(): array
    {
        $cases = [];
        foreach (['fromStream', 'fromOtherDisk', 'fromUrl'] as $method) {
            $cases["{$method}, text"] = [$method, ''];
            $cases["{$method}, an ftyp box of an unknown brand"] = [$method, 'AAAAftypAAAA'];
        }

        return $cases;
    }

    /**
     * What may reach getimagesize(). A row that PHP does not recognise by a
     * signature must be false: PHP would go on to try it as WBMP and XBM.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function signatureProvider(): array
    {
        $ftyp = static fn (int $size, string $brands): string => pack('N', $size).'ftyp'.$brands;

        return [
            'JP2' => ["\x00\x00\x00\x0CjP  \r\n\x87\n", true],
            'JP2 signature cut short' => ["\x00\x00\x00\x0CjP  \r\n\x87", false],
            'WebP' => ['RIFF____WEBPVP8 ', true],
            // PHP itself gives up on these two, and tries nothing else.
            'RIFF that is not WebP' => ['RIFF____AVI LIST', true],
            'PNG' => ["\x89PNG\r\n\x1A\n", true],
            'PNG damaged by a text transfer' => ["\x89PNG\n\x1A\n", true],
            'AVIF as the major brand' => [$ftyp(24, "avif\0\0\0\0mif1miaf"), true],
            'AVIF sequence as the major brand' => [$ftyp(24, "avis\0\0\0\0msf1miaf"), true],
            'AVIF as a compatible brand' => [$ftyp(24, "mif1\0\0\0\0miafavif"), true],
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
            'HEIC, 10 bit' => [$ftyp(24, "heix\0\0\0\0mif1heix"), defined('IMAGETYPE_HEIF')],
            'HEIF' => [$ftyp(8, 'mif1'), defined('IMAGETYPE_HEIF')],
            'HEIF only as a compatible brand' => [$ftyp(24, "isom\0\0\0\0mif1heic"), false],
            'ftyp cut short' => ["\0\0\0\x18ftyp", false],
            'no signature' => ["\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0", false],
            'XBM' => ["#define x_width 1\n#define x_height 1\n", false],
            'Flash' => ['FWS'."\x0A\0\0\0\0", false],
            'compressed Flash' => ['CWS'."\x0A\0\0\0\0", false],
            // PHP compares the first bytes before it looks for an ftyp box.
            'Flash with an AVIF ftyp box' => ["FWS\x0Aftypavif\0\0\0\0mif1", false],
            'compressed Flash with an AVIF ftyp box' => ["CWS\x0Aftypavif\0\0\0\0mif1", false],
            'GIF with an AVIF ftyp box, which is a GIF' => ["GIF\x0Aftypavif\0\0\0\0mif1", true],
            'ftyp one byte late' => ["\0\0\0\0\x18ftypavif\0\0\0\0avifmif1", false],
            'ftyp at the very start' => ["ftypavif\0\0\0\0avifmif1avif", false],
        ];
    }

    #[Test]
    #[DataProvider('signatureProvider')]
    public function it_hands_getimagesize_only_what_php_recognises_by_a_signature(string $head, bool $expected): void
    {
        $hasRasterSignature = new \ReflectionMethod(ImageDimensionsService::class, 'hasRasterSignature');

        $this->assertSame($expected, $hasRasterSignature->invoke(null, $head));

        $size = @getimagesizefromstring($head."\n#define x_width 7\n#define x_height 7\n");
        $type = $size === false ? null : $size[2];

        if ($expected) {
            // What the check promises: PHP settles on a format with a signature.
            $this->assertNotContains($type, self::UNSAFE_TYPES);
        } else {
            // The reason for every "false" above: followed by XBM text, the
            // bytes are XBM or Flash to getimagesize(), or nothing at all.
            $this->assertContains($type, [null, ...self::UNSAFE_TYPES]);
        }
    }

    /**
     * The same promise for bytes nobody thought of: starts PHP knows, cut
     * short or not, followed by an `ftyp` box or pieces of one, and brands in
     * and around the places PHP looks at. Seeded, so every run is the same.
     */
    #[Test]
    public function it_never_hands_getimagesize_what_php_reads_without_a_signature(): void
    {
        $hasRasterSignature = new \ReflectionMethod(ImageDimensionsService::class, 'hasRasterSignature');
        $starts = [
            'GIF8', "\xFF\xD8\xFF\xE0", "\x89PNG", "\x89PN\n", 'FWS'."\x0A", 'CWS'."\x0A", '8BPS', '8BPX', 'BM60',
            "\xFF\x4F\xFF\x51", 'RIFF', 'RIFX', "II\x2A\x00", "MM\x00\x2A", 'FORM', "\x00\x00\x01\x00",
            "\x00\x00\x00\x0C", "\x00\x00\x00\x10", "\x00\x00\x00\x18", "\x00\x00\x00\x20", "\x00\x00\x00\x00",
            "\x00\x00\x00\x01", "\x00\x00\x00\x94", "\x00\x00\xFF\xFF", 'AAAA', "\n\n\n\n",
        ];
        $words = ['ftyp', 'avif', 'avis', 'mif1', 'heic', 'heix', 'mp42', 'isom', 'WEBP', 'jP  ', "\r\n\x87\n", "\0\0\0\0", 'ftyq', 'AVIF'];
        $tail = "\n#define x_width 7\n#define x_height 7\n";

        mt_srand(20261007);
        $accepted = 0;
        for ($i = 0; $i < 20000; $i++) {
            $head = $starts[mt_rand(0, count($starts) - 1)];
            // Mostly a box type where PHP expects it.
            $head .= mt_rand(0, 3) > 0 ? 'ftyp' : $words[mt_rand(0, count($words) - 1)];
            for ($word = mt_rand(0, 40); $word > 0; $word--) {
                $head .= mt_rand(0, 2) > 0 ? $words[mt_rand(0, count($words) - 1)] : 'abcd';
            }
            // Sometimes off the four-byte grid.
            if (mt_rand(0, 9) === 0) {
                $head = substr($head, 0, 8).'x'.substr($head, 8);
            }

            if ($hasRasterSignature->invoke(null, $head)) {
                $accepted++;
                $size = @getimagesizefromstring($head.$tail);
                $this->assertNotContains($size === false ? null : $size[2], self::UNSAFE_TYPES, bin2hex($head));
            }
        }

        // Not vacuous: a good part of the samples is accepted.
        $this->assertGreaterThan(2000, $accepted);
    }

    /**
     * The check above keeps these four types from getimagesize(). Should it
     * ever let one through, its "size" is still not reported.
     *
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function resultTypeProvider(): array
    {
        return [
            'WBMP' => [IMAGETYPE_WBMP, false],
            'XBM' => [IMAGETYPE_XBM, false],
            'Flash' => [IMAGETYPE_SWF, false],
            'compressed Flash' => [IMAGETYPE_SWC, false],
            'PNG' => [IMAGETYPE_PNG, true],
        ];
    }

    #[Test]
    #[DataProvider('resultTypeProvider')]
    public function it_does_not_report_a_size_of_a_type_without_a_signature(int $type, bool $reported): void
    {
        $rasterDimensions = new \ReflectionMethod(ImageDimensionsService::class, 'rasterDimensions');
        $dimensions = $rasterDimensions->invoke(new ImageDimensionsService(['enable_cache' => false]), [33, 17, $type], static fn () => null);

        $this->assertSame($reported ? ['width' => 33, 'height' => 17] : null, $dimensions);
    }

    /**
     * A file of the given size that is one line: no line break in it.
     */
    private function createLargeFile(string $start, string $byte, int $megabytes): string
    {
        $path = $this->tempPath.DIRECTORY_SEPARATOR.'large.bin';
        $file = fopen($path, 'wb');
        fwrite($file, $start);
        for ($megabyte = 0; $megabyte < $megabytes; $megabyte++) {
            fwrite($file, str_repeat($byte, 1024 * 1024));
        }
        fclose($file);

        return $path;
    }

    /**
     * A compressed Flash file (`CWS`) whose body inflates to the given size.
     * Written in pieces: the test itself must not need that much memory.
     *
     * Dressed as AVIF, `ftyp` follows the three bytes and the version, and
     * the compressed data starts with a stored block that puts `avif` where
     * the first compatible brand of the box would be.
     */
    private function createCompressedFlash(int $megabytes, bool $withFtyp = false): string
    {
        $path = $this->tempPath.DIRECTORY_SEPARATOR.'bomb.swf';
        $file = fopen($path, 'wb');
        $megabyte = str_repeat("\0", 1024 * 1024);

        if ($withFtyp) {
            fwrite($file, "CWS\x0Aftyp"."\x78\x01"."\x00\x05\x00\xFA\xFF".'Xavif');
            $deflate = deflate_init(ZLIB_ENCODING_RAW, ['level' => 9]);
            $checksum = hash_init('adler32');
            hash_update($checksum, 'Xavif');
        } else {
            fwrite($file, "CWS\x0A".pack('V', $megabytes * 1024 * 1024 + 8));
            $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
            $checksum = null;
        }

        for ($written = 0; $written < $megabytes; $written++) {
            fwrite($file, (string) deflate_add($deflate, $megabyte, ZLIB_NO_FLUSH));
            if ($checksum !== null) {
                hash_update($checksum, $megabyte);
            }
        }
        fwrite($file, (string) deflate_add($deflate, '', ZLIB_FINISH));
        if ($checksum !== null) {
            fwrite($file, hash_final($checksum, true));
        }
        fclose($file);

        return $path;
    }

    /**
     * What the probe printed: "rejected", "read as WxH", or the fatal error
     * of a process that ran out of memory.
     */
    private function probe(string $method, string $path): string
    {
        $process = proc_open(
            [
                PHP_BINARY,
                '-d', 'memory_limit='.self::PROBE_MEMORY_LIMIT.'M',
                '-d', 'display_errors=stderr',
                dirname(__DIR__).DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'hostile-file-probe.php',
                dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php',
                $method,
                $method === 'fromUrl' ? self::$server->url('/test-file?path='.rawurlencode($path)) : $path,
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
