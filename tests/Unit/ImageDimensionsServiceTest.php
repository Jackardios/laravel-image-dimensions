<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Unit;

use Jackardios\ImageDimensions\Exceptions\FileNotFoundException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * fromLocal() and the argument checks every source shares. SVG geometry,
 * caching and the other sources have their own test classes.
 */
class ImageDimensionsServiceTest extends TestCase
{
    protected ImageDimensionsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImageDimensionsService(['enable_cache' => false]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rasterFormatProvider(): array
    {
        return [
            'png' => ['png'],
            'jpeg' => ['jpg'],
            'gif' => ['gif'],
            'webp' => ['webp'],
            'bmp' => ['bmp'],
        ];
    }

    #[Test]
    #[DataProvider('rasterFormatProvider')]
    public function it_reads_a_local_raster_image(string $format): void
    {
        $this->assertDimensions(30, 40, $this->service->fromLocal($this->createImage("image.{$format}", 30, 40, $format)));
    }

    #[Test]
    public function it_reads_the_format_from_the_contents_not_the_extension(): void
    {
        $this->assertDimensions(30, 40, $this->service->fromLocal($this->createImage('image.jpg', 30, 40, 'png')));
    }

    #[Test]
    public function it_follows_a_symbolic_link(): void
    {
        $target = $this->createImage('image.png', 30, 40);
        $link = $this->tempPath.'/link.png';

        if (! @symlink($target, $link)) {
            $this->markTestSkipped('Creating symbolic links is not permitted in this environment.');
        }

        $this->assertDimensions(30, 40, $this->service->fromLocal($link));
    }

    #[Test]
    public function a_missing_file_is_not_found(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->service->fromLocal($this->tempPath.'/missing.jpg');
    }

    #[Test]
    public function a_directory_is_not_found(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->service->fromLocal($this->tempPath);
    }

    #[Test]
    public function an_empty_path_is_invalid(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Path must be a non-empty string');
        $this->service->fromLocal('');
    }

    #[Test]
    public function an_empty_file_is_invalid(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('File is empty');
        $this->service->fromLocal($this->createFile('empty.png', ''));
    }

    #[Test]
    public function a_file_that_is_no_image_is_invalid(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Could not determine image dimensions');
        $this->service->fromLocal($this->createFile('image.jpg', 'not an image'));
    }

    #[Test]
    public function an_unreadable_file_is_invalid(): void
    {
        $path = $this->createImage('image.jpg', 10, 10, 'jpg');
        $this->makeUnreadable($path);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('File is not readable');
        $this->service->fromLocal($path);
    }

    #[Test]
    public function an_empty_disk_name_is_invalid(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Disk name must be a non-empty string');
        $this->service->fromStorage('', 'image.jpg');
    }

    #[Test]
    public function an_empty_storage_path_is_invalid(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Path must be a non-empty string');
        $this->service->fromStorage('local', '');
    }

    #[Test]
    public function an_unknown_disk_is_invalid(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage("Storage disk 'non-existent' does not exist");
        $this->service->fromStorage('non-existent', 'image.jpg');
    }

    #[Test]
    public function a_url_that_is_no_url_is_invalid(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Invalid URL provided');
        $this->service->fromUrl('not-a-valid-url');
    }

    #[Test]
    public function a_url_with_another_scheme_is_invalid(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Only HTTP and HTTPS URLs are supported');
        $this->service->fromUrl('file:///etc/passwd');
    }
}
