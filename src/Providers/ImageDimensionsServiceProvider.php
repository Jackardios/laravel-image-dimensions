<?php

namespace Jackardios\ImageDimensions\Providers;

use Illuminate\Support\ServiceProvider;
use Jackardios\ImageDimensions\ImageDimensionsService;

class ImageDimensionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton("image-dimensions", function ($app) {
            return new ImageDimensionsService();
        });

        $this->mergeConfigFrom(
            __DIR__ . "/../../config/image-dimensions.php", "image-dimensions"
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . "/../../config/image-dimensions.php" => config_path("image-dimensions.php"),
        ], "image-dimensions-config");
    }
}