<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Unit;

use Jackardios\ImageDimensions\Dimensions;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Support\SvgDimensionsExtractor;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class SvgDimensionsExtractorTest extends TestCase
{
    private SvgDimensionsExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new SvgDimensionsExtractor;
    }

    #[Test]
    public function it_reads_explicit_width_and_height(): void
    {
        $d = $this->extractor->extract('<svg xmlns="http://www.w3.org/2000/svg" width="500" height="600"/>');
        $this->assertEqualsDimensions(500, 600, $d);
    }

    #[Test]
    public function it_reads_pixel_units(): void
    {
        $d = $this->extractor->extract('<svg width="150px" height="250px"/>');
        $this->assertEqualsDimensions(150, 250, $d);
    }

    /**
     * Regression for the v1 viewBox bug: width/height were computed as
     * (max - min) instead of using the raw width/height values.
     */
    #[Test]
    public function it_uses_viewbox_width_height_without_subtracting_the_origin(): void
    {
        $d = $this->extractor->extract('<svg viewBox="10 20 400 300"/>');
        $this->assertEqualsDimensions(400, 300, $d);
    }

    #[Test]
    public function it_accepts_comma_separated_viewbox(): void
    {
        $d = $this->extractor->extract('<svg viewBox="0,0,640,480"/>');
        $this->assertEqualsDimensions(640, 480, $d);
    }

    #[Test]
    public function it_falls_back_to_viewbox_when_dimensions_are_percentages(): void
    {
        $d = $this->extractor->extract('<svg width="100%" height="100%" viewBox="0 0 800 600"/>');
        $this->assertEqualsDimensions(800, 600, $d);
    }

    /**
     * Regression: namespace-prefixed roots (Inkscape output) were rejected
     * because the check compared tagName ("svg:svg") instead of localName.
     */
    #[Test]
    public function it_accepts_a_namespace_prefixed_root(): void
    {
        $d = $this->extractor->extract(
            '<svg:svg xmlns:svg="http://www.w3.org/2000/svg" width="100" height="50"><svg:rect/></svg:svg>'
        );
        $this->assertEqualsDimensions(100, 50, $d);
    }

    /**
     * Regression: the DOCTYPE-stripping regex corrupted an internal subset,
     * so valid SVGs with entity declarations failed to parse.
     */
    #[Test]
    public function it_handles_an_internal_dtd_subset(): void
    {
        $svg = '<!DOCTYPE svg [<!ENTITY nbsp "&#160;">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect/></svg>';

        $d = $this->extractor->extract($svg);
        $this->assertEqualsDimensions(64, 64, $d);
    }

    #[Test]
    #[DataProvider('cssUnitProvider')]
    public function it_converts_css_absolute_units(string $width, string $height, int $expectedWidth, int $expectedHeight): void
    {
        $d = $this->extractor->extract("<svg width=\"{$width}\" height=\"{$height}\"/>");
        $this->assertEqualsDimensions($expectedWidth, $expectedHeight, $d);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: int}>
     */
    public static function cssUnitProvider(): array
    {
        return [
            'inches' => ['1in', '2in', 96, 192],
            'centimeters' => ['1cm', '1cm', 38, 38],   // 37.795 -> ceil 38
            'millimeters' => ['10mm', '10mm', 38, 38], // 37.795 -> ceil 38
            'points' => ['72pt', '72pt', 96, 96],
            'picas' => ['1pc', '1pc', 16, 16],
            'scientific' => ['1e2', '1.5e1', 100, 15],
            'decimal' => ['99.2', '10.9', 100, 11], // ceil
        ];
    }

    #[Test]
    public function it_ignores_a_utf8_bom_and_leading_comment_when_sniffing(): void
    {
        $this->assertTrue(SvgDimensionsExtractor::sniff("\xEF\xBB\xBF<!-- generated --><svg width='1' height='1'/>"));
        $this->assertTrue(SvgDimensionsExtractor::sniff('<?xml version="1.0"?><svg/>'));
        $this->assertTrue(SvgDimensionsExtractor::sniff('<!DOCTYPE svg [<!ENTITY a "b">]><svg/>'));
        $this->assertTrue(SvgDimensionsExtractor::sniff('<svg:svg xmlns:svg="x"/>'));
    }

    #[Test]
    public function it_does_not_sniff_non_svg_content_as_svg(): void
    {
        $this->assertFalse(SvgDimensionsExtractor::sniff('<html><body></body></html>'));
        $this->assertFalse(SvgDimensionsExtractor::sniff("\x89PNG\r\n\x1a\n"));
        $this->assertFalse(SvgDimensionsExtractor::sniff('not xml at all'));
    }

    #[Test]
    public function it_throws_when_no_dimensions_can_be_determined(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Could not determine SVG dimensions');
        $this->extractor->extract('<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>');
    }

    #[Test]
    public function it_throws_for_a_zero_sized_viewbox(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->extractor->extract('<svg viewBox="0 0 0 0"/>');
    }

    #[Test]
    public function it_throws_for_a_non_svg_root(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Root element is not an <svg> element');
        $this->extractor->extract('<html width="10" height="10"></html>');
    }

    #[Test]
    public function it_throws_for_malformed_xml(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->extractor->extract('<?xml version="1.0"?><svg><rect/>');
    }

    /**
     * Regression: a large SVG containing an unterminated <script> made the old
     * catastrophic-backtracking sanitiser return null, which then leaked a
     * TypeError. The extractor no longer runs regex sanitisation, so a huge
     * document either parses or raises a package exception — never a TypeError.
     */
    #[Test]
    public function it_does_not_leak_a_type_error_on_a_huge_document_with_a_script_tag(): void
    {
        $content = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>'
            .str_repeat('<g a="b"/>', 100000).'</svg>';

        try {
            $result = $this->extractor->extract($content);
            $this->assertInstanceOf(Dimensions::class, $result);
        } catch (InvalidImageException $e) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertEqualsDimensions(int $width, int $height, Dimensions $actual): void
    {
        $this->assertSame($width, $actual->width);
        $this->assertSame($height, $actual->height);
    }
}
