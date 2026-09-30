<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Unit;

use InvalidArgumentException;
use Jackardios\ImageDimensions\Dimensions;
use Jackardios\ImageDimensions\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;

class DimensionsTest extends TestCase
{
    #[Test]
    public function it_exposes_width_and_height(): void
    {
        $d = new Dimensions(800, 600);

        $this->assertSame(800, $d->width);
        $this->assertSame(600, $d->height);
    }

    #[Test]
    public function it_rejects_non_positive_dimensions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Dimensions(0, 100);
    }

    #[Test]
    public function it_rejects_negative_dimensions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Dimensions(100, -5);
    }

    #[Test]
    public function it_builds_from_an_array(): void
    {
        $d = Dimensions::fromArray(['width' => '120', 'height' => 80]);

        $this->assertSame(120, $d->width);
        $this->assertSame(80, $d->height);
    }

    #[Test]
    public function it_computes_aspect_ratio_and_orientation(): void
    {
        $landscape = new Dimensions(800, 400);
        $this->assertSame(2.0, $landscape->ratio());
        $this->assertTrue($landscape->isLandscape());
        $this->assertFalse($landscape->isPortrait());
        $this->assertFalse($landscape->isSquare());

        $portrait = new Dimensions(400, 800);
        $this->assertTrue($portrait->isPortrait());

        $square = new Dimensions(500, 500);
        $this->assertTrue($square->isSquare());
        $this->assertFalse($square->isLandscape());
        $this->assertFalse($square->isPortrait());
    }

    #[Test]
    public function it_is_array_accessible_for_v1_compatibility(): void
    {
        $d = new Dimensions(800, 600);

        $this->assertSame(800, $d['width']);
        $this->assertSame(600, $d['height']);
        $this->assertTrue(isset($d['width']));
        $this->assertFalse(isset($d['depth']));
    }

    #[Test]
    public function it_forbids_mutation_through_array_access(): void
    {
        $d = new Dimensions(800, 600);

        try {
            $d['width'] = 1;
            $this->fail('Setting an offset must throw.');
        } catch (LogicException) {
        }

        try {
            unset($d['width']);
            $this->fail('Unsetting an offset must throw.');
        } catch (LogicException) {
        }

        $this->assertSame(800, $d['width']);
    }

    #[Test]
    public function it_rejects_an_unknown_offset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown dimension offset: 'depth'");
        (new Dimensions(800, 600))['depth'];
    }

    #[Test]
    public function it_serializes_to_a_v1_compatible_shape(): void
    {
        $d = new Dimensions(800, 600);

        $this->assertSame(['width' => 800, 'height' => 600], $d->toArray());
        $this->assertSame('{"width":800,"height":600}', json_encode($d));
        $this->assertSame('800x600', (string) $d);
    }
}
