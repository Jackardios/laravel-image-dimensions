<?php

namespace Jackardios\ImageDimensions\Tests;

use Jackardios\ImageDimensions\Providers\ImageDimensionsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ImageDimensionsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default config for tests
        $app["config"]->set("image-dimensions.remote_read_bytes", 65536);
        $app["config"]->set("image-dimensions.temp_dir", sys_get_temp_dir());
    }
}

