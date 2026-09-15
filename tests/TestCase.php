<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests;

use Jackardios\ImageDimensions\Dimensions;
use Jackardios\ImageDimensions\Providers\ImageDimensionsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ImageDimensionsServiceProvider::class];
    }

    /**
     * Assert that a value is a Dimensions object with the given width and height.
     */
    protected function assertDimensions(int $width, int $height, mixed $actual): void
    {
        $this->assertInstanceOf(Dimensions::class, $actual);
        $this->assertSame($width, $actual->width, 'Unexpected width.');
        $this->assertSame($height, $actual->height, 'Unexpected height.');
    }
}
