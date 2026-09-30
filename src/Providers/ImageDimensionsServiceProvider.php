<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Jackardios\ImageDimensions\Contracts\ImageDimensions as ImageDimensionsContract;
use Jackardios\ImageDimensions\ImageDimensionsService;

class ImageDimensionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/image-dimensions.php',
            'image-dimensions'
        );

        // Scoped: built afresh for each request under Octane and each queued
        // job, so configuration changed at run time takes effect.
        $this->app->scoped(ImageDimensionsService::class, function (Application $app) {
            $config = $app->make('config')->get('image-dimensions', []);

            return new ImageDimensionsService(is_array($config) ? $config : []);
        });

        // Resolve the same instance whether the caller type-hints the concrete
        // class, the contract, or the legacy 'image-dimensions' string alias.
        $this->app->alias(ImageDimensionsService::class, ImageDimensionsContract::class);
        $this->app->alias(ImageDimensionsService::class, 'image-dimensions');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/image-dimensions.php' => config_path('image-dimensions.php'),
            ], 'image-dimensions-config');
        }
    }
}
