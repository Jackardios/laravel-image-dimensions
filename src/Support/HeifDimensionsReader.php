<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Support;

/**
 * Reads the dimensions of a HEIF/HEIC image from its metadata.
 *
 * getimagesize() cannot read HEIF before PHP 8.5, and since then reports the
 * coded size, ignoring the clean aperture (a 33x17 image comes out as 64x64).
 * This reads the primary item's image spatial extent (`ispe`), cropped by
 * its clean aperture (`clap`) when there is one. Rotation and mirroring
 * (`irot`, `imir`) are not applied, just as EXIF orientation is not.
 *
 * @internal
 */
final class HeifDimensionsReader
{
    /**
     * `ftyp` brands of HEIF images (HEVC-coded or generic MIAF).
     */
    private const BRANDS = ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'mif1', 'msf1'];

    /**
     * Largest `meta` box read. It holds item and property tables, not image
     * data, and is a few kilobytes even for large grids.
     */
    private const MAX_META_SIZE = 1048576;

    /**
     * Top-level boxes looked through for `meta`. It follows `ftyp`, perhaps
     * after a `free` box or two, and comes before the image data.
     */
    private const MAX_TOP_LEVEL_BOXES = 64;

    /**
     * Bytes read for `ftyp`, which lists a few brands.
     */
    private const FTYP_READ_SIZE = 4096;

    private const MAX_DIMENSION = 2147483647;

    /**
     * @return array{width: int, height: int}|null Null unless the bytes start
     *                                             with a complete HEIF header.
     */
    public static function fromString(string $bytes): ?array
    {
        if (! self::isHeif($bytes)) {
            return null;
        }

        foreach (self::boxes($bytes, 0, strlen($bytes)) as $index => [$type, $start, $end]) {
            if ($type === 'meta') {
                return $end - $start <= self::MAX_META_SIZE ? self::fromMeta(substr($bytes, $start, $end - $start)) : null;
            }

            if ($index === self::MAX_TOP_LEVEL_BOXES - 1) {
                break;
            }
        }

        return null;
    }

    /**
     * @return array{width: int, height: int}|null
     */
    public static function fromFile(string $path): ?array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if (! self::isHeif((string) @fread($handle, self::FTYP_READ_SIZE))) {
                return null;
            }

            $stat = @fstat($handle);
            $fileSize = $stat === false ? 0 : $stat['size'];
            $offset = 0;

            // Top-level box headers only; image data (`mdat`) is skipped.
            for ($index = 0; $index < self::MAX_TOP_LEVEL_BOXES
                && @fseek($handle, $offset) === 0
                && ($box = self::boxHeader((string) @fread($handle, 16), 0, $fileSize - $offset)) !== null; $index++
            ) {
                [$type, $headerSize, $size] = $box;

                if ($type === 'meta') {
                    $length = $size - $headerSize;

                    // Version and flags come first; anything shorter is broken.
                    if ($length < 4 || $length > self::MAX_META_SIZE) {
                        return null;
                    }

                    @fseek($handle, $offset + $headerSize);
                    $meta = (string) @fread($handle, $length);

                    return strlen($meta) === $length ? self::fromMeta($meta) : null;
                }

                $offset += $size;
            }

            return null;
        } finally {
            @fclose($handle);
        }
    }

    private static function isHeif(string $bytes): bool
    {
        if (strlen($bytes) < 12 || substr($bytes, 4, 4) !== 'ftyp') {
            return false;
        }

        $size = self::uint32($bytes, 0);
        $brands = substr($bytes, 8, 4).substr($bytes, 16, max(0, min($size, strlen($bytes), self::FTYP_READ_SIZE) - 16));

        foreach (str_split($brands, 4) as $brand) {
            if (in_array($brand, self::BRANDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string  $meta  The `meta` box content (a full box: version and flags first).
     * @return array{width: int, height: int}|null
     */
    private static function fromMeta(string $meta): ?array
    {
        $primaryItem = null;
        // `ipco` children in order: type followed by content.
        $properties = [];
        // Item ID => 1-based indexes into $properties.
        $associations = [];

        foreach (self::boxes($meta, 4, strlen($meta)) as [$type, $start, $end]) {
            if ($type === 'pitm') {
                $primaryItem = ord($meta[$start] ?? "\x00") === 0
                    ? self::uint16($meta, $start + 4)
                    : self::uint32($meta, $start + 4);
            } elseif ($type === 'iprp') {
                foreach (self::boxes($meta, $start, $end) as [$childType, $childStart, $childEnd]) {
                    if ($childType === 'ipco') {
                        foreach (self::boxes($meta, $childStart, $childEnd) as [$propertyType, $propertyStart, $propertyEnd]) {
                            $properties[] = $propertyType.substr($meta, $propertyStart, $propertyEnd - $propertyStart);
                        }
                    } elseif ($childType === 'ipma') {
                        self::addAssociations($associations, $meta, $childStart, $childEnd);
                    }
                }
            }
        }

        if ($primaryItem === null || ! isset($associations[$primaryItem])) {
            return null;
        }

        $width = $height = null;
        $clap = null;

        foreach ($associations[$primaryItem] as $index) {
            $property = $properties[$index - 1] ?? '';
            $type = substr($property, 0, 4);
            $content = substr($property, 4);

            if ($type === 'ispe' && strlen($content) >= 12) {
                // Full box: version and flags, then width and height.
                $width = self::uint32($content, 4);
                $height = self::uint32($content, 8);
            } elseif ($type === 'clap' && strlen($content) >= 16) {
                $clap = [
                    self::ratio(self::uint32($content, 0), self::uint32($content, 4)),
                    self::ratio(self::uint32($content, 8), self::uint32($content, 12)),
                ];
            }
        }

        if ($width === null || $height === null
            || $width < 1 || $height < 1
            || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
        ) {
            return null;
        }

        // A clean aperture crops the image; one that does not fit is ignored.
        if ($clap !== null
            && $clap[0] !== null && $clap[0] >= 1 && $clap[0] <= $width
            && $clap[1] !== null && $clap[1] >= 1 && $clap[1] <= $height
        ) {
            [$width, $height] = $clap;
        }

        return ['width' => $width, 'height' => $height];
    }

    /**
     * Add the item property associations of an `ipma` box, replacing any
     * earlier ones of the same item.
     *
     * @param  array<int, list<int>>  $associations
     */
    private static function addAssociations(array &$associations, string $data, int $start, int $end): void
    {
        $version = ord($data[$start] ?? "\x00");
        $wideIndexes = (ord($data[$start + 3] ?? "\x00") & 1) === 1;
        $offset = $start + 4;
        $count = self::uint32($data, $offset);
        $offset += 4;

        for ($entry = 0; $entry < $count && $offset < $end; $entry++) {
            if ($version < 1) {
                $item = self::uint16($data, $offset);
                $offset += 2;
            } else {
                $item = self::uint32($data, $offset);
                $offset += 4;
            }

            $associationCount = ord($data[$offset] ?? "\x00");
            $offset++;

            $indexes = [];
            for ($i = 0; $i < $associationCount && $offset < $end; $i++) {
                // The top bit flags the property as essential.
                if ($wideIndexes) {
                    $indexes[] = self::uint16($data, $offset) & 0x7FFF;
                    $offset += 2;
                } else {
                    $indexes[] = ord($data[$offset] ?? "\x00") & 0x7F;
                    $offset++;
                }
            }

            $associations[$item] = $indexes;
        }
    }

    /**
     * Child boxes between two offsets, up to the first that does not fit.
     *
     * @return iterable<array{0: string, 1: int, 2: int}> Type, content start, box end.
     */
    private static function boxes(string $data, int $offset, int $end): iterable
    {
        while (($box = self::boxHeader($data, $offset, $end)) !== null) {
            [$type, $headerSize, $size] = $box;

            yield [$type, $offset + $headerSize, $offset + $size];

            $offset += $size;
        }
    }

    /**
     * The header of the box at $offset, or null if the box does not fit
     * before $end. Bytes missing at the end of $data read as zeros, so a
     * truncated header does not fit either.
     *
     * @return array{0: string, 1: int, 2: int}|null Type, header size and box size.
     */
    private static function boxHeader(string $data, int $offset, int $end): ?array
    {
        $size = self::uint32($data, $offset);
        $type = substr($data, $offset + 4, 4);
        $headerSize = 8;

        if ($size === 1) {
            // A size of 2^63 or more wraps around to a negative one, which
            // is rejected below.
            $size = (self::uint32($data, $offset + 8) << 32) | self::uint32($data, $offset + 12);
            $headerSize = 16;
        } elseif ($size === 0) {
            // The box extends to the end.
            $size = $end - $offset;
        }

        return $size >= $headerSize && $size <= $end - $offset ? [$type, $headerSize, $size] : null;
    }

    private static function ratio(int $numerator, int $denominator): ?int
    {
        if ($denominator === 0) {
            return null;
        }

        // Rounded to the nearest pixel, halves up.
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    private static function uint16(string $data, int $offset): int
    {
        $value = unpack('n', substr($data, $offset, 2).str_repeat("\x00", 2));

        return $value === false ? 0 : $value[1];
    }

    private static function uint32(string $data, int $offset): int
    {
        $value = unpack('N', substr($data, $offset, 4).str_repeat("\x00", 4));

        return $value === false ? 0 : $value[1];
    }
}
