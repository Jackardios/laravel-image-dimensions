<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use DOMDocument;
use Illuminate\Http\UploadedFile;
use Jackardios\ImageDimensions\Exceptions\FileTooLargeException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class SvgDimensionsTest extends TestCase
{
    private ImageDimensionsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ImageDimensionsService(['enable_cache' => false]);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: int, 2: int}>
     */
    public static function geometryProvider(): array
    {
        return [
            'inches' => [['width' => '2in', 'height' => '1in'], 192, 96],
            'centimetres' => [['width' => '2.54cm', 'height' => '1.27cm'], 96, 48],
            'millimetres' => [['width' => '25.4mm', 'height' => '10mm'], 96, 38],
            'points and picas' => [['width' => '72pt', 'height' => '6pc'], 96, 96],
            'upper-case unit' => [['width' => '10PX', 'height' => '1IN'], 10, 96],
            'exponent' => [['width' => '1e2', 'height' => '5E1'], 100, 50],
            'viewBox size ignores min-x/min-y' => [['viewBox' => '10 20 400 300'], 400, 300],
            'negative viewBox origin' => [['viewBox' => '-50 -50 100 80'], 100, 80],
            'width scaled by viewBox ratio' => [['width' => '200', 'viewBox' => '0 0 100 50'], 200, 100],
            'height scaled by viewBox ratio' => [['height' => '100', 'viewBox' => '0 0 100 50'], 200, 100],
            'relative width falls back to viewBox ratio' => [['width' => '10em', 'height' => '60', 'viewBox' => '0 0 4 3'], 80, 60],
            'float noise does not round up' => [['width' => '3', 'viewBox' => '0 0 0.3 0.1'], 3, 1],
            'out-of-range length falls back' => [['width' => '1e400', 'height' => '20', 'viewBox' => '0 0 10 10'], 20, 20],
            'finite length beyond an int falls back' => [['width' => '1e300', 'height' => '20', 'viewBox' => '0 0 10 10'], 20, 20],
            'largest int' => [['width' => '2147483647', 'height' => '1'], 2147483647, 1],
            'surrounding whitespace' => [['width' => ' 100 ', 'height' => "\t50\n"], 100, 50],
            'garbage before the number falls back' => [['width' => 'x100', 'height' => '50', 'viewBox' => '0 0 10 10'], 50, 50],
            'negative length falls back' => [['width' => '-100', 'height' => '50', 'viewBox' => '0 0 10 10'], 50, 50],
            'both sides set ignore the viewBox' => [['width' => '100', 'height' => '100', 'viewBox' => '0 0 200 50'], 100, 100],
            'viewBox with commas and padding' => [['viewBox' => ' 0,0 , 30,20 '], 30, 20],
            'noise below a millionth of a pixel is dropped' => [['width' => '1.0000001', 'height' => '1'], 1, 1],
            'a millionth of a pixel still rounds up' => [['width' => '1.000001', 'height' => '1'], 2, 1],
        ];
    }

    /**
     * @param  array<string, string>  $attributes
     */
    #[Test]
    #[DataProvider('geometryProvider')]
    public function it_resolves_svg_geometry(array $attributes, int $width, int $height): void
    {
        $this->assertDimensions($width, $height, $this->service->fromLocal($this->createSvg('image.svg', $attributes)));
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function unresolvableGeometryProvider(): array
    {
        return [
            'explicit zero width' => [['width' => '0', 'height' => '10', 'viewBox' => '0 0 5 5']],
            'explicit zero height' => [['width' => '10', 'height' => '0']],
            'zero-sized viewBox' => [['viewBox' => '0 0 0 10']],
            'negative viewBox size' => [['viewBox' => '0 0 -10 10']],
            'malformed viewBox' => [['viewBox' => '0 0 wide tall']],
            'too large to be an int' => [['width' => '3000000000', 'height' => '1']],
            'scaled beyond an int' => [['width' => '2000000000', 'viewBox' => '0 0 1 2']],
            'viewBox width with a unit' => [['viewBox' => '0 0 10px 20']],
            'viewBox height with a unit' => [['viewBox' => '0 0 10 20px']],
            'three-value viewBox' => [['viewBox' => '0 0 10']],
            'infinite viewBox' => [['viewBox' => '0 0 1e400 10']],
            // Scaling by these would divide by zero.
            'width with a zero-width viewBox' => [['width' => '10', 'viewBox' => '0 0 0 10']],
            'height with a zero-height viewBox' => [['height' => '10', 'viewBox' => '0 0 10 0']],
        ];
    }

    /**
     * @param  array<string, string>  $attributes
     */
    #[Test]
    #[DataProvider('unresolvableGeometryProvider')]
    public function it_rejects_unresolvable_svg_geometry(array $attributes): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Could not determine SVG dimensions');
        $this->service->fromLocal($this->createSvg('image.svg', $attributes));
    }

    /**
     * Scripts and event handlers are neither run nor removed: the extractor
     * only reads the geometry. Serving an uploaded SVG safely is the
     * application's job.
     */
    #[Test]
    public function it_reads_an_svg_that_contains_scripts(): void
    {
        $path = $this->createSvg('script.svg', ['width' => 100, 'height' => 50], '<script>alert("XSS")</script><rect onclick="alert(1)" width="100" height="50"/>');

        $this->assertDimensions(100, 50, $this->service->fromLocal($path));
        $this->assertStringContainsString('<script>', (string) file_get_contents($path));
    }

    #[Test]
    public function it_reports_the_xml_parser_error(): void
    {
        $path = $this->createFile('malformed.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect>');

        try {
            $this->service->fromLocal($path);
            $this->fail('Malformed XML must be rejected.');
        } catch (InvalidImageException $e) {
            $this->assertStringStartsWith('Could not parse SVG: ', $e->getMessage());

            // libxml's wording differs between versions; check only that its
            // own (trimmed) message is used rather than the generic fallback.
            $reason = substr($e->getMessage(), strlen('Could not parse SVG: '));
            $this->assertNotSame('', $reason);
            $this->assertNotSame('invalid XML content', $reason);
            $this->assertSame(trim($reason), $reason);
        }
    }

    #[Test]
    public function it_ignores_libxml_errors_left_over_by_other_code(): void
    {
        $previous = libxml_use_internal_errors(true);

        try {
            (new DOMDocument)->loadXML('<a></b>');
            $this->assertNotSame([], libxml_get_errors());

            try {
                $this->service->fromLocal($this->createFile('malformed.svg', '<svg><rect>'));
                $this->fail('Malformed XML must be rejected.');
            } catch (InvalidImageException $e) {
                $this->assertStringNotContainsString('mismatch', $e->getMessage());
            }

            $this->assertSame([], libxml_get_errors(), 'Errors from the SVG parse must be cleared.');
            $this->assertTrue(libxml_use_internal_errors(), 'The caller\'s libxml error mode must be kept.');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    #[Test]
    public function it_restores_the_libxml_error_mode(): void
    {
        $previous = libxml_use_internal_errors(false);

        try {
            $this->assertDimensions(1, 1, $this->service->fromLocal($this->createSvg('ok.svg', ['width' => '1', 'height' => '1'])));
            $this->assertFalse(libxml_use_internal_errors());

            try {
                $this->service->fromLocal($this->createFile('malformed.svg', '<svg><rect>'));
                $this->fail('Malformed XML must be rejected.');
            } catch (InvalidImageException) {
            }

            $this->assertFalse(libxml_use_internal_errors());
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    #[Test]
    public function it_parses_documents_with_an_internal_dtd_subset(): void
    {
        // The shape Adobe Illustrator exports.
        $path = $this->createFile('illustrator.svg', '<?xml version="1.0" encoding="utf-8"?>'."\n"
            .'<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd" ['."\n"
            .'  <!ENTITY ns_extend "http://ns.adobe.com/Extensibility/1.0/">'."\n"
            .']>'."\n"
            .'<svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:x="&ns_extend;" width="100px" height="50px"/>');

        $this->assertDimensions(100, 50, $this->service->fromLocal($path));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function geometryAttributeProvider(): array
    {
        return [
            'width' => ['<svg xmlns="http://www.w3.org/2000/svg" width="&n;" height="5"/>'],
            'height' => ['<svg xmlns="http://www.w3.org/2000/svg" width="5" height="&n;"/>'],
            'viewBox' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 &n; &n;"/>'],
        ];
    }

    #[Test]
    #[DataProvider('geometryAttributeProvider')]
    public function it_rejects_entity_references_in_geometry_attributes(string $root): void
    {
        // libxml expands such references on attribute access in quadratic
        // time and without any limit: ~50 KB of them pin a CPU for seconds.
        $path = $this->createFile('entity.svg', '<!DOCTYPE svg [<!ENTITY n "5">]>'.$root);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Entity references are not supported');
        $this->service->fromLocal($path);
    }

    /**
     * The payload is named .png: the format comes from the contents, so it
     * must not reach getimagesize(), which parses SVG itself on PHP 8.5
     * without any protection against entity expansion.
     */
    #[Test]
    #[DataProvider('entityPayloadNameProvider')]
    public function it_rejects_a_large_entity_expansion_payload_quickly(string $filename): void
    {
        $path = $this->createFile($filename, '<!DOCTYPE svg [<!ENTITY a "'.str_repeat('1', 1000).'">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg" width="'.str_repeat('&a;', 16000).'" height="1"/>');

        $started = microtime(true);

        try {
            $this->service->fromLocal($path);
            $this->fail('The entity payload must be rejected.');
        } catch (InvalidImageException $e) {
            // libxml 2.11+ stops the parse itself: the references in this
            // attribute would amplify the document a thousandfold. Earlier
            // releases parse it and leave the rejection to the guard.
            $this->assertMatchesRegularExpression(
                '/^(Entity references are not supported|Could not parse SVG: Maximum entity amplification factor exceeded)/',
                $e->getMessage(),
            );
        }

        // Expanding this payload takes ~5 s; the guard needs milliseconds.
        $this->assertLessThan(1.0, microtime(true) - $started);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function entityPayloadNameProvider(): array
    {
        return [
            'svg name' => ['bomb.svg'],
            'raster name' => ['bomb.png'],
        ];
    }

    #[Test]
    public function it_ignores_entity_references_outside_the_geometry_attributes(): void
    {
        $path = $this->createFile('title.svg', '<!DOCTYPE svg [<!ENTITY t "Title">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg" width="30" height="20" data-title="&t;"><title>&t;</title></svg>');

        $this->assertDimensions(30, 20, $this->service->fromLocal($path));
    }

    #[Test]
    public function it_recognises_svg_by_content_whatever_the_name(): void
    {
        // Named .png, an SVG used to reach getimagesize(): an error on
        // PHP <= 8.4, and on 8.5 (which reads SVG itself, ignoring units) 2x1.
        $path = $this->createSvg('logo.png', ['width' => '2in', 'height' => '1in']);

        $this->assertDimensions(192, 96, $this->service->fromLocal($path));
    }

    #[Test]
    public function it_recognises_svg_by_content_when_the_mime_type_is_generic(): void
    {
        // A long leading comment makes libmagic report text/xml.
        $path = $this->createFile('commented', '<?xml version="1.0"?><!--'.str_repeat('c', 5000).'-->'
            .'<svg xmlns="http://www.w3.org/2000/svg" width="2in" height="1in"/>');

        $this->assertDimensions(192, 96, $this->service->fromLocal($path));
    }

    #[Test]
    public function it_recognises_svg_with_a_bom_and_leading_whitespace(): void
    {
        $path = $this->createFile('bom', "\xEF\xBB\xBF\n  <svg xmlns=\"http://www.w3.org/2000/svg\" width=\"1in\" height=\"2in\"/>");

        $this->assertDimensions(96, 192, $this->service->fromLocal($path));
    }

    #[Test]
    public function it_reads_a_raster_image_named_svg(): void
    {
        $path = $this->createImage('photo.svg', 30, 20);

        $this->assertDimensions(30, 20, $this->service->fromLocal($path));
    }

    #[Test]
    public function it_reads_a_raster_upload_whose_client_name_says_svg(): void
    {
        $path = $this->createImage('phpUPLOAD', 30, 20);
        $upload = new UploadedFile($path, 'logo.svg', 'image/svg+xml', null, true);

        $this->assertDimensions(30, 20, $this->service->fromUploadedFile($upload));
    }

    #[Test]
    public function it_rejects_markup_that_is_not_svg(): void
    {
        $path = $this->createFile('page.png', '<!DOCTYPE html><html><body>Not found</body></html>');

        $this->expectException(InvalidImageException::class);
        $this->service->fromLocal($path);
    }

    #[Test]
    public function it_rejects_a_non_svg_root_element(): void
    {
        $path = $this->createFile('html.svg', '<html width="20" height="10"/>');

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Root element is not an <svg> element');
        $this->service->fromLocal($path);
    }

    #[Test]
    public function it_accepts_an_svg_exactly_at_the_size_limit(): void
    {
        $content = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="20"/>';
        $service = new ImageDimensionsService(['enable_cache' => false, 'svg' => ['max_file_size' => strlen($content)]]);

        $this->assertDimensions(10, 20, $service->fromLocal($this->createFile('exact.svg', $content)));
    }

    /**
     * The size of an SVG file is checked before it is read.
     */
    #[Test]
    public function an_svg_file_over_the_limit_is_not_read(): void
    {
        $path = $this->createFile('huge.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="20">');
        // 64 MB, without writing them (a sparse file where supported).
        $handle = fopen($path, 'r+b');
        ftruncate($handle, 64 * 1048576);
        fclose($handle);
        $service = new ImageDimensionsService(['enable_cache' => false, 'svg' => ['max_file_size' => 1024]]);

        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        try {
            $service->fromLocal($path);
            $this->fail('Expected a FileTooLargeException.');
        } catch (FileTooLargeException $e) {
            $this->assertStringContainsString('SVG file is too large (max 1024 bytes)', $e->getMessage());
        }

        $this->assertLessThan(8 * 1048576, memory_get_peak_usage() - $before);
    }

    #[Test]
    public function it_names_the_limit_when_an_svg_is_too_large(): void
    {
        $content = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="20"/>';
        $limit = strlen($content) - 1;
        $service = new ImageDimensionsService(['enable_cache' => false, 'svg' => ['max_file_size' => $limit]]);

        $this->expectException(FileTooLargeException::class);
        $this->expectExceptionMessage("SVG file is too large (max {$limit} bytes)");
        $service->fromLocal($this->createFile('over.svg', $content));
    }

    #[Test]
    public function it_applies_the_svg_size_limit_to_svg_recognised_by_content(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => false, 'svg' => ['max_file_size' => 1024]]);
        $path = $this->createFile('large.png', '<?xml version="1.0"?><!--'.str_repeat('c', 2000).'-->'
            .'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>');

        $this->expectException(FileTooLargeException::class);
        $service->fromLocal($path);
    }
}
