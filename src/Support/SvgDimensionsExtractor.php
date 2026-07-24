<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Support;

use DOMDocument;
use InvalidArgumentException;
use Jackardios\ImageDimensions\Dimensions;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;

/**
 * Extracts pixel dimensions from SVG markup.
 *
 * Security note: the document is parsed with LIBXML_NONET (no network access)
 * and WITHOUT LIBXML_NOENT, so external and general entities are not expanded.
 * libxml's built-in entity-expansion limits neutralise "billion laughs" style
 * bombs. No regex-based sanitisation is performed — it broke valid documents
 * (internal DTD subsets, entities) without adding protection, since this class
 * only ever reads the root element's geometry and returns two integers.
 */
final class SvgDimensionsExtractor
{
    /**
     * CSS absolute length units expressed as pixels, assuming 96 DPI.
     */
    /**
     * Largest accepted pixel dimension.
     *
     * Values above this (including INF from something like `1e400`) cannot be
     * cast to int without wrapping to a garbage or negative number, so they are
     * rejected rather than silently corrupted.
     */
    private const MAX_DIMENSION = 2147483647;

    private const UNIT_TO_PIXELS = [
        'px' => 1.0,
        'pt' => 96.0 / 72.0,   // ≈ 1.3333
        'pc' => 16.0,          // 1pc = 12pt
        'in' => 96.0,
        'cm' => 96.0 / 2.54,   // ≈ 37.7953
        'mm' => 96.0 / 25.4,   // ≈ 3.7795
    ];

    /**
     * Determine the dimensions of the given SVG markup.
     *
     * @throws InvalidImageException
     */
    public function extract(string $content): Dimensions
    {
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $doc = new DOMDocument;

            if (! $doc->loadXML($content, LIBXML_NONET)) {
                $errors = libxml_get_errors();
                $reason = $errors !== [] ? trim($errors[0]->message) : 'invalid XML content';
                throw new InvalidImageException("Could not parse SVG: {$reason}");
            }

            $svg = $doc->documentElement;
            if ($svg === null || $svg->localName !== 'svg') {
                throw new InvalidImageException('Root element is not an <svg> element.');
            }

            $width = $this->parseLength($svg->getAttribute('width'));
            $height = $this->parseLength($svg->getAttribute('height'));

            if (($width === null || $height === null) && $svg->hasAttribute('viewBox')) {
                [$viewBoxWidth, $viewBoxHeight] = $this->parseViewBox($svg->getAttribute('viewBox'));
                $width ??= $viewBoxWidth;
                $height ??= $viewBoxHeight;
            }

            if ($width === null || $height === null) {
                throw new InvalidImageException('Could not determine SVG dimensions.');
            }

            try {
                return new Dimensions($width, $height);
            } catch (InvalidArgumentException $e) {
                // Never let a non-package exception escape the contract.
                throw new InvalidImageException("Invalid SVG dimensions: {$e->getMessage()}", 0, $e);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    /**
     * Cheaply decide whether a byte string looks like SVG markup, skipping any
     * leading XML prolog, comments and DOCTYPE declaration. Used to recognise
     * SVGs whose filename/MIME type is unavailable (e.g. streamed temp files).
     */
    public static function sniff(string $bytes): bool
    {
        // Drop a UTF-8 BOM if present.
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }

        $length = strlen($bytes);
        $offset = 0;

        while ($offset < $length) {
            // Skip whitespace between top-level nodes.
            while ($offset < $length && ctype_space($bytes[$offset])) {
                $offset++;
            }
            if ($offset >= $length || $bytes[$offset] !== '<') {
                return false;
            }

            $rest = substr($bytes, $offset);

            if (preg_match('/^<\?xml\b.*?\?>/is', $rest, $m)) {
                $offset += strlen($m[0]);

                continue;
            }

            if (preg_match('/^<!--.*?-->/s', $rest, $m)) {
                $offset += strlen($m[0]);

                continue;
            }

            if (preg_match('/^<!DOCTYPE/i', $rest)) {
                $consumed = self::skipDoctype($rest);
                if ($consumed === null) {
                    return false; // truncated inside the sniff window
                }
                $offset += $consumed;

                continue;
            }

            // First real element: an <svg> root, optionally namespace-prefixed.
            return (bool) preg_match('/^<([A-Za-z_][\w.-]*:)?svg[\s>\/]/', $rest);
        }

        return false;
    }

    /**
     * Number of bytes occupied by a leading DOCTYPE declaration, accounting for
     * an optional bracketed internal subset. Null if it is not terminated
     * within the given string.
     */
    private static function skipDoctype(string $rest): ?int
    {
        $bracket = strpos($rest, '[');
        $gt = strpos($rest, '>');

        if ($bracket !== false && ($gt === false || $bracket < $gt)) {
            $close = strpos($rest, ']', $bracket);
            $gt = $close !== false ? strpos($rest, '>', $close) : false;
        }

        return $gt === false ? null : $gt + 1;
    }

    /**
     * Parse an SVG length attribute (width/height) into a pixel count.
     *
     * Supports plain numbers and the CSS absolute units px/pt/pc/cm/mm/in.
     * Percentages, relative units (em/ex), "auto" and unparseable values
     * return null so the caller can fall back to the viewBox.
     */
    private function parseLength(string $value): ?int
    {
        $value = trim($value);

        if ($value === '' || strcasecmp($value, 'auto') === 0) {
            return null;
        }

        if (! preg_match('/^\+?(\d*\.?\d+(?:[eE][+-]?\d+)?)\s*(px|pt|pc|cm|mm|in)?$/i', $value, $m)) {
            return null;
        }

        $unit = isset($m[2]) ? strtolower($m[2]) : 'px';
        $pixels = (float) $m[1] * self::UNIT_TO_PIXELS[$unit];

        return self::toPixels($pixels);
    }

    /**
     * Convert a computed float length to a positive pixel count, rejecting
     * non-finite and out-of-range values instead of letting the int cast wrap.
     */
    private static function toPixels(float $pixels): ?int
    {
        if (! is_finite($pixels) || $pixels <= 0 || $pixels > self::MAX_DIMENSION) {
            return null;
        }

        return (int) ceil($pixels);
    }

    /**
     * Parse a viewBox ("min-x min-y width height") into its width/height.
     *
     * The width and height are the third and fourth values as-is — they are NOT
     * offset by min-x/min-y (that was a bug in v1).
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function parseViewBox(string $value): array
    {
        $parts = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) !== 4) {
            return [null, null];
        }

        return [
            self::toPixels((float) $parts[2]),
            self::toPixels((float) $parts[3]),
        ];
    }
}
