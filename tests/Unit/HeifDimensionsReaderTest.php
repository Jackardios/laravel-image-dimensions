<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Unit;

use Jackardios\ImageDimensions\Support\HeifDimensionsReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HeifDimensionsReaderTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = (string) tempnam(sys_get_temp_dir(), 'imgdim_heif_test_');
    }

    protected function tearDown(): void
    {
        @unlink($this->tempFile);
    }

    /**
     * Encoded with libheif; the sizes are what heif-info reports.
     *
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function fixtureProvider(): array
    {
        return [
            // Coded as 64x64 and cropped by a clean aperture. PHP 8.5's
            // getimagesize() reports 64x64.
            'cropped' => ['p33x17.heic', 33, 17],
            'grid of 2x2 tiles' => ['grid1000.heic', 1000, 700],
            'grid of 8x6 tiles' => ['grid.heic', 4032, 3024],
            'single image' => ['p4032x3024.heic', 4032, 3024],
        ];
    }

    #[Test]
    #[DataProvider('fixtureProvider')]
    public function it_reads_encoded_images(string $fixture, int $width, int $height): void
    {
        $path = self::fixture($fixture);

        $this->assertSame(['width' => $width, 'height' => $height], HeifDimensionsReader::fromFile($path));
        $this->assertSame(['width' => $width, 'height' => $height], HeifDimensionsReader::fromString((string) file_get_contents($path)));
    }

    #[Test]
    public function it_ignores_what_is_not_heif(): void
    {
        $mp4 = self::box('ftyp', 'mp42'.pack('N', 0).'isommp42').self::box('mdat', 'x');
        // The same boxes a HEIF image has, but not its brands.
        $mp4WithMetadata = self::heif([self::ispe(300, 200)], [1 => [1]], majorBrand: 'mp42', compatibleBrands: 'isommp42');

        foreach (['', 'ftyp', str_repeat("\0", 64), "\x89PNG\r\n\x1a\n".str_repeat("\0", 32), $mp4, $mp4WithMetadata] as $bytes) {
            $this->assertNull(HeifDimensionsReader::fromString($bytes));
            $this->assertNull($this->fromFile($bytes));
        }

        $this->assertNull(HeifDimensionsReader::fromFile(sys_get_temp_dir().'/imgdim_missing.heic'));
    }

    #[Test]
    public function it_recognises_heif_by_a_compatible_brand(): void
    {
        $bytes = self::heif([self::ispe(40, 30)], [1 => [1]], majorBrand: 'MiHB', compatibleBrands: 'MiHBMiHAheicmif1');

        $this->assertSame(['width' => 40, 'height' => 30], HeifDimensionsReader::fromString($bytes));
        $this->assertSame(['width' => 40, 'height' => 30], $this->fromFile($bytes));
    }

    #[Test]
    public function it_reads_the_primary_item(): void
    {
        // Item 1 is a thumbnail; item 2, the primary item, is the image.
        $bytes = self::heif([self::ispe(160, 120), self::ispe(4000, 3000)], [1 => [1], 2 => [2]], primaryItem: 2);

        $this->assertSame(['width' => 4000, 'height' => 3000], HeifDimensionsReader::fromString($bytes));
        $this->assertSame(['width' => 4000, 'height' => 3000], $this->fromFile($bytes));
    }

    /**
     * @return array<string, array{0: int, 1: bool, 2: int}>
     */
    public static function boxVersionProvider(): array
    {
        return [
            'ipma v0, 7-bit indexes' => [0, false, 0],
            'ipma v0, 15-bit indexes' => [0, true, 0],
            'ipma v1, 7-bit indexes' => [1, false, 0],
            'ipma v1, 15-bit indexes' => [1, true, 0],
            'pitm v1' => [0, false, 1],
        ];
    }

    #[Test]
    #[DataProvider('boxVersionProvider')]
    public function it_reads_every_box_version(int $ipmaVersion, bool $wideIndexes, int $pitmVersion): void
    {
        // Item 5 comes first and item 7 has two properties, of which only the
        // third one is its size, so a misread entry or index shows.
        $bytes = self::heif(
            [self::box('free', ''), self::ispe(1, 1), self::ispe(640, 480)],
            [5 => [2], 7 => [1, 3]],
            primaryItem: 7,
            ipmaVersion: $ipmaVersion,
            wideIndexes: $wideIndexes,
            pitmVersion: $pitmVersion,
        );

        $this->assertSame(['width' => 640, 'height' => 480], HeifDimensionsReader::fromString($bytes));
    }

    /**
     * @return array<string, array{0: list<int>, 1: array{width: int, height: int}}>
     */
    public static function clapProvider(): array
    {
        return [
            'crops' => [[33, 1, 17, 1], ['width' => 33, 'height' => 17]],
            'rounds to the nearest pixel' => [[67, 2, 101, 3], ['width' => 34, 'height' => 34]],
            'ignored when wider than the image' => [[65, 1, 17, 1], ['width' => 64, 'height' => 64]],
            'ignored when empty' => [[0, 1, 17, 1], ['width' => 64, 'height' => 64]],
            'ignored with a zero denominator' => [[33, 0, 17, 1], ['width' => 64, 'height' => 64]],
        ];
    }

    /**
     * @param  list<int>  $clap  Width and height, as fractions.
     * @param  array{width: int, height: int}  $expected
     */
    #[Test]
    #[DataProvider('clapProvider')]
    public function it_applies_a_valid_clean_aperture(array $clap, array $expected): void
    {
        $bytes = self::heif([self::ispe(64, 64), self::clap(...$clap)], [1 => [1, 2]]);

        $this->assertSame($expected, HeifDimensionsReader::fromString($bytes));
    }

    #[Test]
    public function the_essential_flag_is_not_part_of_the_index(): void
    {
        foreach ([false, true] as $wideIndexes) {
            $bytes = self::heif([self::ispe(1, 1), self::ispe(2, 2), self::ispe(20, 10)], [1 => [3 | 0x80]], wideIndexes: $wideIndexes);

            $this->assertSame(['width' => 20, 'height' => 10], HeifDimensionsReader::fromString($bytes));
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function incompleteMetadataProvider(): array
    {
        return [
            'no spatial extent' => [self::heif([self::clap(1, 1, 1, 1)], [1 => [1]])],
            'primary item without properties' => [self::heif([self::ispe(1, 1)], [2 => [1]])],
            'index past the properties' => [self::heif([self::ispe(1, 1)], [1 => [2]])],
            'empty spatial extent' => [self::heif([self::ispe(0, 10)], [1 => [1]])],
            'no primary item' => [self::box('ftyp', 'heic'.pack('N', 0).'mif1heic').self::fullBox('meta', 0, 0, '')],
            // fread() with a length of 0 throws a ValueError.
            'empty meta box' => [self::box('ftyp', 'heic'.pack('N', 0).'mif1heic').self::box('meta', '')],
        ];
    }

    #[Test]
    #[DataProvider('incompleteMetadataProvider')]
    public function it_gives_up_on_incomplete_metadata(string $bytes): void
    {
        $this->assertNull(HeifDimensionsReader::fromString($bytes));
        $this->assertNull($this->fromFile($bytes));
    }

    #[Test]
    public function it_skips_top_level_boxes_before_the_metadata(): void
    {
        $image = self::heif([self::ispe(300, 200)], [1 => [1]]);
        [$ftyp, $meta] = self::splitAfterFtyp($image);

        // A box with a 64-bit size, then an ordinary one.
        $largeSize = pack('N', 1).'free'.pack('NN', 0, 16 + 3).'abc';
        $bytes = $ftyp.$largeSize.self::box('free', str_repeat("\0", 50000)).$meta;

        $this->assertSame(['width' => 300, 'height' => 200], HeifDimensionsReader::fromString($bytes));
        $this->assertSame(['width' => 300, 'height' => 200], $this->fromFile($bytes));
    }

    #[Test]
    public function it_reads_a_metadata_box_with_a_64_bit_size(): void
    {
        [$ftyp, $meta] = self::splitAfterFtyp(self::heif([self::ispe(300, 200)], [1 => [1]]));
        $content = substr($meta, 8, unpack('N', $meta)[1] - 8);
        $bytes = $ftyp.pack('N', 1).'meta'.pack('NN', 0, 16 + strlen($content)).$content;

        $this->assertSame(['width' => 300, 'height' => 200], HeifDimensionsReader::fromString($bytes));
        $this->assertSame(['width' => 300, 'height' => 200], $this->fromFile($bytes));
    }

    /**
     * A box of 4 GB and 19 bytes, where the file is much shorter: whatever
     * follows its first 19 bytes is inside it, not a box of its own.
     */
    #[Test]
    public function it_does_not_look_inside_a_box_for_the_next_one(): void
    {
        [$ftyp, $meta] = self::splitAfterFtyp(self::heif([self::ispe(300, 200)], [1 => [1]]));
        $bytes = $ftyp.pack('N', 1).'free'.pack('NN', 1, 16 + 3).'abc'.$meta;

        $this->assertNull(HeifDimensionsReader::fromString($bytes));
        $this->assertNull($this->fromFile($bytes));
    }

    #[Test]
    public function it_rejects_a_size_that_does_not_fit_in_an_integer(): void
    {
        [$ftyp, $meta] = self::splitAfterFtyp(self::heif([self::ispe(300, 200)], [1 => [1]]));
        $content = substr($meta, 8, unpack('N', $meta)[1] - 8);

        foreach ([0x80000000, 0xFFFFFFFF] as $high) {
            $bytes = $ftyp.pack('N', 1).'meta'.pack('NN', $high, 16 + strlen($content)).$content;

            $this->assertNull(HeifDimensionsReader::fromString($bytes));
            $this->assertNull($this->fromFile($bytes));
        }
    }

    #[Test]
    public function it_reads_a_metadata_box_that_extends_to_the_end(): void
    {
        [$ftyp, $meta] = self::splitAfterFtyp(self::heif([self::ispe(300, 200)], [1 => [1]]));
        $bytes = $ftyp.pack('N', 0).substr($meta, 4);

        $this->assertSame(['width' => 300, 'height' => 200], HeifDimensionsReader::fromString($bytes));
        $this->assertSame(['width' => 300, 'height' => 200], $this->fromFile($bytes));
    }

    #[Test]
    public function the_start_of_an_image_gives_its_size_or_nothing(): void
    {
        foreach (['p33x17.heic' => [33, 17], 'grid1000.heic' => [1000, 700]] as $fixture => [$width, $height]) {
            $bytes = (string) file_get_contents(self::fixture($fixture));

            for ($length = 0; $length <= strlen($bytes); $length++) {
                $head = substr($bytes, 0, $length);

                foreach ([HeifDimensionsReader::fromString($head), $this->fromFile($head)] as $result) {
                    if ($result !== null) {
                        $this->assertSame(['width' => $width, 'height' => $height], $result, "{$fixture}, {$length} bytes");
                    }
                }
            }

            $this->assertNotNull(HeifDimensionsReader::fromString($head), 'The whole image must be readable.');
        }
    }

    /**
     * Every byte of a real image, in turn, replaced by a few values: the
     * reader returns null or a plausible size, and never warns or throws.
     * Warnings fail the test (failOnWarning).
     */
    #[Test]
    public function it_survives_corrupted_images(): void
    {
        $bytes = (string) file_get_contents(self::fixture('p33x17.heic'));

        for ($offset = 0; $offset < strlen($bytes); $offset++) {
            foreach (["\x00", "\x01", "\x7f", "\x80", "\xff"] as $value) {
                $corrupted = substr_replace($bytes, $value, $offset, 1);
                $result = HeifDimensionsReader::fromString($corrupted);

                if ($result !== null) {
                    $this->assertGreaterThan(0, $result['width']);
                    $this->assertGreaterThan(0, $result['height']);
                }

                $this->assertSame($result, $this->fromFile($corrupted), "Offset {$offset}");
            }
        }
    }

    private function fromFile(string $bytes): ?array
    {
        file_put_contents($this->tempFile, $bytes);

        return HeifDimensionsReader::fromFile($this->tempFile);
    }

    private static function fixture(string $name): string
    {
        return dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.$name;
    }

    /**
     * A HEIF file with just the boxes that give the size.
     *
     * @param  list<string>  $properties  `ipco` children.
     * @param  array<int, list<int>>  $associations  Item ID => 1-based property indexes.
     */
    private static function heif(
        array $properties,
        array $associations,
        int $primaryItem = 1,
        int $ipmaVersion = 0,
        bool $wideIndexes = false,
        int $pitmVersion = 0,
        string $majorBrand = 'heic',
        string $compatibleBrands = 'mif1heic',
    ): string {
        $ipma = pack('N', count($associations));
        foreach ($associations as $item => $indexes) {
            $ipma .= $ipmaVersion < 1 ? pack('n', $item) : pack('N', $item);
            $ipma .= chr(count($indexes));
            foreach ($indexes as $index) {
                $ipma .= $wideIndexes ? pack('n', ($index & 0x7F) | (($index & 0x80) << 8)) : chr($index);
            }
        }

        $pitm = self::fullBox('pitm', $pitmVersion, 0, $pitmVersion < 1 ? pack('n', $primaryItem) : pack('N', $primaryItem));
        $iprp = self::box('iprp', self::box('ipco', implode('', $properties)).self::fullBox('ipma', $ipmaVersion, $wideIndexes ? 1 : 0, $ipma));

        return self::box('ftyp', $majorBrand.pack('N', 0).$compatibleBrands)
            .self::fullBox('meta', 0, 0, self::fullBox('hdlr', 0, 0, pack('N', 0).'pict'.str_repeat("\0", 13)).$pitm.$iprp)
            .self::box('mdat', str_repeat("\0", 16));
    }

    private static function ispe(int $width, int $height): string
    {
        return self::fullBox('ispe', 0, 0, pack('NN', $width, $height));
    }

    private static function clap(int $widthN, int $widthD, int $heightN, int $heightD): string
    {
        return self::box('clap', pack('NNNNNNNN', $widthN, $widthD, $heightN, $heightD, 0, 1, 0, 1));
    }

    private static function box(string $type, string $content): string
    {
        return pack('N', 8 + strlen($content)).$type.$content;
    }

    private static function fullBox(string $type, int $version, int $flags, string $content): string
    {
        return self::box($type, chr($version).substr(pack('N', $flags), 1).$content);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitAfterFtyp(string $bytes): array
    {
        $size = unpack('N', $bytes)[1];

        return [substr($bytes, 0, $size), substr($bytes, $size)];
    }
}
