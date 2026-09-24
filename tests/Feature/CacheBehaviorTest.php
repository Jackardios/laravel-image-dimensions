<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\TestCase;
use League\Flysystem\FileAttributes;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToRetrieveMetadata;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function noCachingProvider(): array
    {
        return [
            'TTL of 0' => [['enable_cache' => true, 'cache_ttl' => 0]],
            'negative TTL' => [['enable_cache' => true, 'cache_ttl' => -1]],
            'cache turned off' => [['enable_cache' => false, 'cache_ttl' => 3600]],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[Test]
    #[DataProvider('noCachingProvider')]
    public function the_cache_can_be_turned_off(array $config): void
    {
        $service = new ImageDimensionsService($config);

        Cache::shouldReceive('get')->never();
        Cache::shouldReceive('put')->never();
        Cache::shouldReceive('forever')->never();

        $this->assertDimensions(40, 20, $service->fromLocal($this->png));
    }

    #[Test]
    public function a_cached_entry_is_served_without_reading_the_file(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => true, 'cache_ttl' => 3600]);

        Cache::shouldReceive('get')
            ->once()
            ->with(Mockery::pattern('/^image_dimensions:v2:local:[0-9a-f]{32}$/'))
            ->andReturn(['width' => 11, 'height' => 22]);

        $this->assertDimensions(11, 22, $service->fromLocal($this->png));
    }

    #[Test]
    public function it_writes_keys_under_the_v2_namespace(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => true, 'cache_ttl' => 3600]);
        $keys = $this->recordCacheWrites();

        $service->fromLocal($this->png);

        $this->assertCount(1, $keys);
        $this->assertMatchesRegularExpression('/^image_dimensions:v2:local:[0-9a-f]{32}$/', $keys[0]);
        $this->assertSame(['width' => 40, 'height' => 20], Cache::get($keys[0]));
    }

    /**
     * Regression: the key was the path plus the modification time, so a file
     * rewritten within the same second, or replaced by one with the same
     * modification time (cp -p, an atomic rename), kept the old dimensions.
     */
    #[Test]
    public function a_rewrite_that_keeps_the_modification_time_is_not_served_from_the_cache(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => true, 'cache_ttl' => 3600]);
        $mtime = (int) filemtime($this->png);
        $this->assertDimensions(40, 20, $service->fromLocal($this->png));

        // In place: the ctime changes.
        file_put_contents($this->png, $this->imageBytes(20, 40));
        touch($this->png, $mtime);
        clearstatcache();
        $this->assertDimensions(20, 40, $service->fromLocal($this->png));

        // Replaced by another file: the inode changes.
        $replacement = $this->createImage('replacement.png', 30, 10);
        touch($replacement, $mtime);
        rename($replacement, $this->png);
        clearstatcache();
        $this->assertDimensions(30, 10, $service->fromLocal($this->png));
    }

    /**
     * Regression: the storage key joined the disk name and the path with a
     * colon, so disk "a:b" + path "c" and disk "a" + path "b:c" shared one.
     */
    #[Test]
    public function storage_keys_keep_the_disk_and_the_path_apart(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => true, 'cache_ttl' => 3600]);
        $this->useInMemoryDisk('photos:2024')->put('cat.png', $this->imageBytes(1, 2));
        $this->useInMemoryDisk('photos')->put('2024:cat.png', $this->imageBytes(3, 4));

        $this->assertDimensions(1, 2, $service->fromStorage('photos:2024', 'cat.png'));
        $this->assertDimensions(3, 4, $service->fromStorage('photos', '2024:cat.png'));
    }

    /**
     * Regression: a cache hit on a cloud disk cost two remote calls, one to
     * check that the file exists and one for its modification time.
     */
    #[Test]
    public function a_storage_cache_hit_costs_one_metadata_call(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => true, 'cache_ttl' => 3600]);
        $adapter = new class extends InMemoryFilesystemAdapter
        {
            /** @var list<string> */
            public array $calls = [];

            public function fileExists(string $path): bool
            {
                $this->calls[] = 'fileExists';

                return parent::fileExists($path);
            }

            public function lastModified(string $path): FileAttributes
            {
                $this->calls[] = 'lastModified';

                return parent::lastModified($path);
            }
        };
        $this->useInMemoryDisk('cloud', $adapter)->put('a.png', $this->imageBytes(5, 6));
        $adapter->calls = [];

        $this->assertDimensions(5, 6, $service->fromStorage('cloud', 'a.png'));
        $this->assertDimensions(5, 6, $service->fromStorage('cloud', 'a.png'));

        $this->assertSame(['lastModified', 'lastModified'], $adapter->calls);
    }

    /**
     * The modification time is part of a storage key: a changed file is
     * measured again.
     */
    #[Test]
    public function a_changed_storage_file_is_not_served_from_the_cache(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => true, 'cache_ttl' => 3600]);
        $adapter = new class extends InMemoryFilesystemAdapter
        {
            public int $modified = 1000;

            public function lastModified(string $path): FileAttributes
            {
                return new FileAttributes($path, null, null, $this->modified);
            }
        };
        $disk = $this->useInMemoryDisk('cloud', $adapter);

        $disk->put('a.png', $this->imageBytes(5, 6));
        $this->assertDimensions(5, 6, $service->fromStorage('cloud', 'a.png'));

        // Same modification time: the cached entry.
        $disk->put('a.png', $this->imageBytes(7, 8));
        $this->assertDimensions(5, 6, $service->fromStorage('cloud', 'a.png'));

        $adapter->modified = 1001;
        $this->assertDimensions(7, 8, $service->fromStorage('cloud', 'a.png'));
    }

    /**
     * Some drivers cannot tell the modification time; the file is then
     * checked for existence and cached by its path.
     */
    #[Test]
    public function a_storage_file_without_a_modification_time_is_still_measured_and_cached(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => true, 'cache_ttl' => 3600]);
        $disk = $this->useInMemoryDisk('cloud', new class extends InMemoryFilesystemAdapter
        {
            public function lastModified(string $path): FileAttributes
            {
                throw UnableToRetrieveMetadata::lastModified($path, 'Not supported');
            }
        });
        $disk->put('a.png', $this->imageBytes(5, 6));
        $keys = $this->recordCacheWrites();

        $this->assertDimensions(5, 6, $service->fromStorage('cloud', 'a.png'));
        $this->assertCount(1, $keys);

        $this->expectException(FileNotFoundException::class);
        $service->fromStorage('cloud', 'missing.png');
    }

    #[Test]
    public function a_missing_storage_file_is_reported_as_not_found(): void
    {
        $this->useInMemoryDisk('cloud');

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage("File not found on disk 'cloud': missing.png");
        (new ImageDimensionsService)->fromStorage('cloud', 'missing.png');
    }
}
