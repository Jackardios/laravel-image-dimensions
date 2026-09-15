<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\Concerns\CreatesTestImages;
use Jackardios\ImageDimensions\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

class CacheBehaviorTest extends TestCase
{
    use CreatesTestImages;

    private string $dir;

    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/imgdim_cache_'.uniqid();
        mkdir($this->dir, 0777, true);
        $this->png = $this->createImage($this->dir, 'pic.png', 40, 20);
    }

    protected function tearDown(): void
    {
        $this->cleanupCreatedFiles($this->dir);
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function a_null_ttl_caches_forever(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => true, 'cache_ttl' => null]);

        Cache::shouldReceive('rememberForever')
            ->once()
            ->andReturn(['width' => 40, 'height' => 20]);
        Cache::shouldReceive('remember')->never();

        $this->assertDimensions(40, 20, $service->fromLocal($this->png));
    }

    #[Test]
    public function a_positive_ttl_uses_remember(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => true, 'cache_ttl' => 120]);

        Cache::shouldReceive('remember')
            ->once()
            ->with(Mockery::type('string'), 120, Mockery::type('callable'))
            ->andReturn(['width' => 40, 'height' => 20]);

        $this->assertDimensions(40, 20, $service->fromLocal($this->png));
    }

    #[Test]
    public function a_zero_or_negative_ttl_disables_caching(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => true, 'cache_ttl' => 0]);

        Cache::shouldReceive('remember')->never();
        Cache::shouldReceive('rememberForever')->never();

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
