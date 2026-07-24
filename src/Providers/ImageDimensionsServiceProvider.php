<?php declare(strict_types=1);

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
            __DIR__ . '/../../config/image-dimensions.php',
            'image-dimensions'
        );

        $this->app->singleton(ImageDimensionsService::class, function (Application $app) {
            return new ImageDimensionsService($app['config']->get('image-dimensions', []));
        });

        // Resolve the same singleton whether the caller type-hints the concrete
        // class, the contract, or the legacy 'image-dimensions' string alias.
        $this->app->alias(ImageDimensionsService::class, ImageDimensionsContract::class);
        $this->app->alias(ImageDimensionsService::class, 'image-dimensions');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/image-dimensions.php' => config_path('image-dimensions.php'),
            ], 'image-dimensions-config');
        }
    }
}
