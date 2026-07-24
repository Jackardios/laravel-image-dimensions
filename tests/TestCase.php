<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests;

use Jackardios\ImageDimensions\Providers\ImageDimensionsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ImageDimensionsServiceProvider::class];
    }
}
