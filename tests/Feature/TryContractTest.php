<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\StorageAccessException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\TestCase;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * from*() throw only package exceptions for bad input or a failing source,
 * so tryFrom*() can return null instead.
 */
class TryContractTest extends TestCase
{
    private ImageDimensionsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ImageDimensionsService(['cache_ttl' => 3600]);
    }

    #[Test]
    public function a_local_path_with_a_nul_byte_is_invalid_input(): void
    {
        $this->assertNull($this->service->tryFromLocal($this->createImage('a.png', 1, 1)."\0.jpg"));

        $this->expectException(InvalidImageException::class);
        $this->service->fromLocal("image\0.png");
    }

    #[Test]
    public function a_storage_path_with_a_nul_byte_is_invalid_input(): void
    {
        $this->useInMemoryDisk('mem')->put('a.png', $this->imageBytes(1, 1));

        $this->assertNull($this->service->tryFromStorage('mem', "a\0.png"));
        $this->assertNull($this->service->tryFromStorage('local', "a\0.png"));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function diskProvider(): array
    {
        return ['local disk' => ['local'], 'non-local disk' => ['mem']];
    }

    #[Test]
    #[DataProvider('diskProvider')]
    public function a_storage_path_leaving_the_disk_is_invalid_input(string $disk): void
    {
        $this->useInMemoryDisk('mem');
        $this->createImage('outside.png', 1, 1);

        $this->assertNull($this->service->tryFromStorage($disk, '../../../../../../../../../../'.ltrim($this->tempPath, '/').'/outside.png'));

        $this->expectException(InvalidImageException::class);
        $this->service->fromStorage($disk, '../outside.png');
    }

    #[Test]
    public function a_failing_existence_check_is_a_storage_error(): void
    {
        $this->useInMemoryDisk('flaky', new class extends InMemoryFilesystemAdapter
        {
            public function fileExists(string $path): bool
            {
                throw UnableToCheckFileExistence::forLocation($path, new RuntimeException('Service unavailable'));
            }

            public function lastModified(string $path): FileAttributes
            {
                throw UnableToRetrieveMetadata::lastModified($path, 'Service unavailable');
            }
        });

        $this->assertNull($this->service->tryFromStorage('flaky', 'a.png'));

        try {
            $this->service->fromStorage('flaky', 'a.png');
            $this->fail('Expected a StorageAccessException.');
        } catch (StorageAccessException $e) {
            $this->assertInstanceOf(UnableToCheckFileExistence::class, $e->getPrevious());
        }
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function throwingDiskProvider(): array
    {
        return ['disk that returns null' => [false], 'disk that throws' => [true]];
    }

    #[Test]
    #[DataProvider('throwingDiskProvider')]
    public function a_file_that_cannot_be_read_is_a_storage_error(bool $throws): void
    {
        $adapter = new class extends InMemoryFilesystemAdapter
        {
            public function readStream(string $path)
            {
                throw UnableToReadFile::fromLocation($path, 'Access denied');
            }
        };
        $adapter->write('a.png', $this->imageBytes(1, 1), new Config);
        // Laravel disks swallow the driver's exception unless 'throw' is set.
        Storage::set('flaky', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => $throws]));

        $this->assertNull($this->service->tryFromStorage('flaky', 'a.png'));

        try {
            $this->service->fromStorage('flaky', 'a.png');
            $this->fail('Expected a StorageAccessException.');
        } catch (StorageAccessException $e) {
            $this->assertSame('Could not read stream from storage file: a.png', $e->getMessage());
            $throws
                ? $this->assertInstanceOf(UnableToReadFile::class, $e->getPrevious())
                : $this->assertNull($e->getPrevious());
        }
    }

    /**
     * Only failures of the source become null: a broken application, say a
     * misconfigured filesystem driver, is not hidden.
     */
    #[Test]
    public function a_failure_outside_the_package_is_not_hidden(): void
    {
        Storage::shouldReceive('disk')->andThrow(new RuntimeException('Driver [s4] is not supported.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Driver [s4] is not supported.');
        $this->service->tryFromStorage('any', 'a.png');
    }

    #[Test]
    public function a_resource_that_is_not_a_stream_is_invalid_input(): void
    {
        $context = stream_context_create();

        $this->assertNull($this->service->tryFromStream($context));

        $this->expectException(InvalidImageException::class);
        $this->service->fromStream($context);
    }

    #[Test]
    public function a_closed_stream_is_invalid_input(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fclose($stream);

        $this->assertNull($this->service->tryFromStream($stream));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function corruptCacheEntryProvider(): array
    {
        return [
            'string' => ['garbage'],
            'missing height' => [['width' => 5]],
            'zero width' => [['width' => 0, 'height' => 5]],
            'zero height' => [['width' => 5, 'height' => 0]],
            'non-numeric' => [['width' => 'wide', 'height' => 'tall']],
            'object' => [new \stdClass],
        ];
    }

    /**
     * A foreign or corrupted entry under the package's key is a cache miss,
     * not an error, and is replaced by the real dimensions.
     */
    #[Test]
    #[DataProvider('corruptCacheEntryProvider')]
    public function a_corrupt_cache_entry_is_recomputed(mixed $entry): void
    {
        $path = $this->createImage('a.png', 7, 9);
        $this->service->fromLocal($path);
        $key = $this->onlyCacheKey();

        Cache::put($key, $entry, 3600);

        $this->assertDimensions(7, 9, $this->service->fromLocal($path));
        $this->assertSame(['width' => 7, 'height' => 9], Cache::get($key));
    }

    #[Test]
    public function a_failing_cache_store_is_not_hidden(): void
    {
        // Documented: tryFrom*() only turns failures of the source into null.
        Cache::shouldReceive('get')->andThrow(new RuntimeException('Connection refused'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection refused');
        $this->service->tryFromLocal($this->createImage('a.png', 1, 1));
    }

    #[Test]
    public function a_missing_file_is_still_not_found(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->service->fromLocal($this->tempPath.'/missing.png');
    }

    private function onlyCacheKey(): string
    {
        $store = Cache::store()->getStore();
        $keys = array_keys((fn () => $this->storage)->call($store));
        $this->assertCount(1, $keys);

        return $keys[0];
    }
}
