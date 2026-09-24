<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

class CacheBehaviorTest extends TestCase
{
    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        $this->png = $this->createImage('pic.png', 40, 20);
    }

    #[Test]
    public function a_null_ttl_caches_forever(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => true, 'cache_ttl' => null]);

        Cache::shouldReceive('get')->once()->andReturn(null);
        Cache::shouldReceive('forever')
            ->once()
            ->with(Mockery::type('string'), ['width' => 40, 'height' => 20]);
        Cache::shouldReceive('put')->never();

        $this->assertDimensions(40, 20, $service->fromLocal($this->png));
    }

    #[Test]
    public function a_positive_ttl_expires(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => true, 'cache_ttl' => 120]);

        Cache::shouldReceive('get')->once()->andReturn(null);
        Cache::shouldReceive('put')
            ->once()
            ->with(Mockery::type('string'), ['width' => 40, 'height' => 20], 120);

        $this->assertDimensions(40, 20, $service->fromLocal($this->png));
    }

    #[Test]
    public function a_zero_or_negative_ttl_disables_caching(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => true, 'cache_ttl' => 0]);

        Cache::shouldReceive('get')->never();
        Cache::shouldReceive('put')->never();
        Cache::shouldReceive('forever')->never();

        $this->assertDimensions(40, 20, $service->fromLocal($this->png));
    }

    #[Test]
    public function it_writes_keys_under_the_v2_namespace(): void
    {
        Cache::flush();
        $service = new ImageDimensionsService(['enable_cache' => true, 'cache_ttl' => 3600]);

        $service->fromLocal($this->png);

        $key = 'image_dimensions:v2:local:'.md5(realpath($this->png)).':'.filemtime($this->png);
        $this->assertTrue(Cache::has($key));
    }
}
