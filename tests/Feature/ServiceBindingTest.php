<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Jackardios\ImageDimensions\Contracts\ImageDimensions as ImageDimensionsContract;
use Jackardios\ImageDimensions\Facades\ImageDimensions;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ServiceBindingTest extends TestCase
{
    #[Test]
    public function the_service_is_a_shared_singleton_across_every_binding(): void
    {
        $byClass = $this->app->make(ImageDimensionsService::class);
        $byString = $this->app->make('image-dimensions');
        $byContract = $this->app->make(ImageDimensionsContract::class);

        $this->assertInstanceOf(ImageDimensionsService::class, $byClass);
        $this->assertSame($byClass, $byString, 'String alias should resolve the same singleton.');
        $this->assertSame($byClass, $byContract, 'Contract alias should resolve the same singleton.');
    }

    #[Test]
    public function the_facade_resolves_the_same_singleton(): void
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
