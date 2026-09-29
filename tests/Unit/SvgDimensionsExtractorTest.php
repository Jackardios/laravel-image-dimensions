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
    public function it_recognises_markup_after_a_bom_and_whitespace(): void
    {
        $this->assertTrue(SvgDimensionsExtractor::startsWithMarkup('<svg/>'));
        $this->assertTrue(SvgDimensionsExtractor::startsWithMarkup("\xEF\xBB\xBF<!-- generated --><svg/>"));
        $this->assertTrue(SvgDimensionsExtractor::startsWithMarkup(" \t\r\n<?xml version=\"1.0\"?><svg/>"));
        $this->assertTrue(SvgDimensionsExtractor::startsWithMarkup("\xEF\xBB\xBF\n<svg/>"));
    }

    #[Test]
    public function it_does_not_take_other_content_for_markup(): void
    {
        $this->assertFalse(SvgDimensionsExtractor::startsWithMarkup(''));
        $this->assertFalse(SvgDimensionsExtractor::startsWithMarkup("\x89PNG\r\n\x1a\n"));
        $this->assertFalse(SvgDimensionsExtractor::startsWithMarkup('not xml at all'));
        // Only a UTF-8 BOM and XML whitespace may precede the markup.
        $this->assertFalse(SvgDimensionsExtractor::startsWithMarkup("\xEF\xBB<svg/>"));
        $this->assertFalse(SvgDimensionsExtractor::startsWithMarkup("\x0B<svg/>"));
        $this->assertFalse(SvgDimensionsExtractor::startsWithMarkup("\x00<svg/>"));
    }

    #[Test]
    public function it_throws_when_no_dimensions_can_be_determined(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Could not determine SVG dimensions');
        $this->extractor->extract('<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>');
    }

    /**
     * Regression: `(float) '1e400'` is INF and `(int) ceil(INF)` is 0, which made
     * the Dimensions constructor throw a raw InvalidArgumentException — a
     * non-package exception that escaped the contract (and tryFrom*()).
     */
    #[Test]
    #[DataProvider('outOfRangeLengthProvider')]
    public function it_rejects_out_of_range_lengths_instead_of_overflowing(string $svg): void
    {
        $this->expectException(InvalidImageException::class);
        $this->extractor->extract($svg);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function outOfRangeLengthProvider(): array
    {
        return [
            'infinite width' => ['<svg width="1e400" height="10"/>'],
            'huge exponent width' => ['<svg width="1e30" height="10"/>'],
            'huge integer width' => ['<svg width="99999999999999999999" height="10"/>'],
            'huge viewBox width' => ['<svg viewBox="0 0 1e30 10"/>'],
            'huge viewBox height' => ['<svg viewBox="0 0 10 1e30"/>'],
            'huge unit conversion' => ['<svg width="1e29in" height="10"/>'],
        ];
    }

    private function assertEqualsDimensions(int $width, int $height, Dimensions $actual): void
    {
        $this->assertSame($width, $actual->width);
        $this->assertSame($height, $actual->height);
    }
}
