<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Illuminate\Support\Facades\Facade;
use Jackardios\ImageDimensions\Contracts\ImageDimensions as ImageDimensionsContract;
use Jackardios\ImageDimensions\Facades\ImageDimensions;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ServiceBindingTest extends TestCase
{
    #[Test]
    public function every_binding_resolves_the_same_instance(): void
    {
        $byClass = $this->app->make(ImageDimensionsService::class);
        $byString = $this->app->make('image-dimensions');
        $byContract = $this->app->make(ImageDimensionsContract::class);

        $this->assertInstanceOf(ImageDimensionsService::class, $byClass);
        $this->assertSame($byClass, $byString, 'String alias should resolve the same instance.');
        $this->assertSame($byClass, $byContract, 'Contract alias should resolve the same instance.');
    }

    /**
     * What a queue worker does between jobs, and Octane between requests.
     */
    #[Test]
    public function the_service_is_rebuilt_for_the_next_job_with_the_current_config(): void
    {
        $first = ImageDimensions::getFacadeRoot();
        $this->assertSame($first, $this->app->make(ImageDimensionsService::class));

        config(['image-dimensions.max_download_bytes' => 5000000]);
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstances();

        $next = ImageDimensions::getFacadeRoot();
        $this->assertNotSame($first, $next);
        $this->assertSame($next, $this->app->make(ImageDimensionsContract::class));
        $this->assertSame(5000000, (fn () => $this->maxDownloadBytes)->call($next));
    }

    #[Test]
    public function the_facade_resolves_the_same_instance(): void
    {
        $this->assertSame(
            $this->app->make(ImageDimensionsService::class),
            ImageDimensions::getFacadeRoot()
        );
    }

    #[Test]
    public function it_can_be_injected_by_the_contract(): void
    {
        $resolved = $this->app->call(function (ImageDimensionsContract $service) {
            return $service;
        });

        $this->assertInstanceOf(ImageDimensionsService::class, $resolved);
    }
}
