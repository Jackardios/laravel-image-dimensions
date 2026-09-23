<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Support;

use DOMAttr;
use DOMDocument;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;

/**
 * Extracts pixel dimensions from SVG markup.
 *
 * The document is parsed with LIBXML_NONET and without LIBXML_NOENT, so no
 * entity is ever loaded or substituted. Entity references inside the geometry
 * attributes are rejected before they are read: libxml expands them on access
 * without any amplification limit, in quadratic time.
 *
 * @internal
 */
final class SvgDimensionsExtractor
{
    /**
     * Largest accepted pixel dimension; anything above (including INF from a
     * value like `1e400`) would wrap around when cast to int.
     */
    private const MAX_DIMENSION = 2147483647;

    /**
     * CSS absolute length units expressed in pixels, at 96 DPI.
     */
    private const UNIT_TO_PIXELS = [
        'px' => 1.0,
        'pt' => 96.0 / 72.0,
        'pc' => 16.0,
        'in' => 96.0,
        'cm' => 96.0 / 2.54,
        'mm' => 96.0 / 25.4,
    ];

    private const GEOMETRY_ATTRIBUTES = ['width', 'height', 'viewBox'];

    /**
     * Whether the bytes start with markup (after an optional UTF-8 BOM and
     * whitespace). No raster format does, so such content must never be
     * handed to getimagesize(): PHP 8.5 parses SVG there itself, ignoring
     * units and without protection against entity expansion.
     */
    public static function startsWithMarkup(string $bytes): bool
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }

        return str_starts_with(ltrim($bytes, " \t\r\n"), '<');
    }

    /**
     * @param  string  $label  Used in exception messages (usually the file path).
     * @return array{width: int, height: int}
     *
     * @throws InvalidImageException
     */
    public function extract(string $content, string $label): array
    {
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $doc = new DOMDocument;

            if (! $doc->loadXML($content, LIBXML_NONET)) {
                $errors = libxml_get_errors();
                $reason = $errors !== [] ? trim($errors[0]->message) : 'Invalid XML content';

                throw InvalidImageException::forPath($label, $reason);
            }

            $svg = $doc->documentElement;
            if ($svg === null || $svg->localName !== 'svg') {
                throw new InvalidImageException("Invalid SVG file: {$label}");
            }

            foreach (self::GEOMETRY_ATTRIBUTES as $name) {
                $attribute = $svg->getAttributeNode($name);
                if (! $attribute instanceof DOMAttr) {
                    continue;
                }

                foreach ($attribute->childNodes as $child) {
                    if ($child->nodeType === XML_ENTITY_REF_NODE) {
                        throw InvalidImageException::forPath($label, "Entity references are not supported in the SVG {$name} attribute.");
                    }
                }
            }

            $width = $this->parseLength($svg->getAttribute('width'));
            $height = $this->parseLength($svg->getAttribute('height'));

            if (($width === null || $height === null) && $svg->hasAttribute('viewBox')) {
                $viewBox = $this->parseViewBox($svg->getAttribute('viewBox'));

                if ($viewBox !== null) {
                    [$viewBoxWidth, $viewBoxHeight] = $viewBox;

                    if ($width === null && $height === null) {
                        [$width, $height] = $viewBox;
                    } elseif ($width === null) {
                        // Keep the viewBox aspect ratio, as browsers do.
                        $width = $height * $viewBoxWidth / $viewBoxHeight;
                    } else {
                        $height = $width * $viewBoxHeight / $viewBoxWidth;
                    }
                }
            }

            $width = $this->toPixels($width);
            $height = $this->toPixels($height);

            if ($width === null || $height === null) {
                throw new InvalidImageException("Could not determine SVG dimensions: {$label}");
            }

            return ['width' => $width, 'height' => $height];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    /**
     * Parse a width/height attribute into pixels: a plain number or one with
     * a CSS absolute unit. Percentages, relative units, "auto" and anything
     * unparseable yield null, so the caller can fall back to the viewBox.
     */
    private function parseLength(string $value): ?float
    {
        $value = trim($value);

        if (! preg_match('/^\+?(?<number>\d*\.?\d+(?:e[+-]?\d+)?)\s*(?<unit>px|pt|pc|in|cm|mm)?$/i', $value, $m)) {
            return null;
        }

        $pixels = (float) $m['number'] * self::UNIT_TO_PIXELS[strtolower($m['unit'] ?? '') ?: 'px'];

        return is_finite($pixels) && $pixels <= self::MAX_DIMENSION ? $pixels : null;
    }

    /**
     * The width and height of a "min-x min-y width height" viewBox, taken
     * as-is (they are not offsets from min-x/min-y). Null unless both are
     * positive numbers; an infinite one is left for toPixels() to reject.
     *
     * @return array{0: float, 1: float}|null
     */
    private function parseViewBox(string $value): ?array
    {
        $parts = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) !== 4 || ! is_numeric($parts[2]) || ! is_numeric($parts[3])) {
            return null;
        }

        [$width, $height] = [(float) $parts[2], (float) $parts[3]];

        return $width > 0 && $height > 0 ? [$width, $height] : null;
    }

    /**
     * Round a length up to whole pixels. Zero, like an unresolved length, is
     * not a usable dimension.
     */
    private function toPixels(?float $pixels): ?int
    {
        if ($pixels === null) {
            return null;
        }

        // Drop float noise first: 0.1 * 3 * 10 is 3.0000000000000004.
        $pixels = ceil(round($pixels, 6));

        return $pixels >= 1 && $pixels <= self::MAX_DIMENSION ? (int) $pixels : null;
    }
}
