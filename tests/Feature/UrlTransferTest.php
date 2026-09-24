<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Jackardios\ImageDimensions\Exceptions\FileTooLargeException;
use Jackardios\ImageDimensions\Exceptions\InvalidImageException;
use Jackardios\ImageDimensions\Exceptions\UrlAccessException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Tests\Support\LocalHttpServer;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Real transfers against a local server: Http::fake() bypasses cURL, so the
 * deadline, the early stop and the redirect handling only show up here.
 */
class UrlTransferTest extends TestCase
{
    private static LocalHttpServer $server;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$server = LocalHttpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests(false);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function service(array $config = []): ImageDimensionsService
    {
        return new ImageDimensionsService(array_replace_recursive([
            'enable_cache' => false,
            'url' => ['allow_private_hosts' => true],
            'http' => ['timeout' => 5, 'connect_timeout' => 2],
        ], $config));
    }

    #[Test]
    public function it_stops_the_download_once_the_header_gives_the_dimensions(): void
    {
        // Downloading the 50 MB tail would exceed the 1 MB cap.
        $service = $this->service(['max_download_bytes' => 1048576]);

        $this->assertDimensions(640, 480, $service->fromUrl(self::$server->url('/png-with-tail')));
    }

    #[Test]
    public function it_stops_early_without_a_content_length(): void
    {
        $service = $this->service(['max_download_bytes' => 1048576]);

        $this->assertDimensions(640, 480, $service->fromUrl(self::$server->url('/png-with-tail?chunked=1')));
    }

    #[Test]
    public function it_stops_the_download_once_the_heif_metadata_gives_the_dimensions(): void
    {
        $service = $this->service(['max_download_bytes' => 1048576]);

        $this->assertDimensions(33, 17, $service->fromUrl(self::$server->url('/heif?tail=50000000')));
    }

    #[Test]
    public function it_keeps_reading_until_the_heif_metadata_arrives(): void
    {
        $this->assertDimensions(33, 17, $this->service()->fromUrl(self::$server->url('/heif?pad=200000')));
    }

    #[Test]
    public function it_does_not_stop_at_bytes_that_look_like_a_wbmp_header(): void
    {
        // getimagesize() reads the first bytes as a 128x64 WBMP image.
        $service = $this->service(['max_download_bytes' => 1048576]);

        $this->expectException(FileTooLargeException::class);
        $service->fromUrl(self::$server->url('/wbmp-like?tail=50000000'));
    }

    #[Test]
    public function a_declared_length_over_the_cap_does_not_reject_an_image_whose_header_suffices(): void
    {
        // The PNG is ~920 KB; its header fits in remote_read_bytes.
        $service = $this->service(['max_download_bytes' => 131072]);

        $this->assertDimensions(640, 480, $service->fromUrl(self::$server->url('/png-with-tail?tail=0')));
    }

    #[Test]
    public function it_rejects_a_declared_length_over_the_cap_once_the_header_is_not_enough(): void
    {
        $service = $this->service(['max_download_bytes' => 1048576]);

        $started = microtime(true);

        try {
            // An SVG is only measured as a whole document.
            $service->fromUrl(self::$server->url('/svg?padding=3000000'));
            $this->fail('Expected a FileTooLargeException.');
        } catch (FileTooLargeException $e) {
            $this->assertStringContainsString('too large', $e->getMessage());
        }

        $this->assertLessThan(2.0, microtime(true) - $started);
    }

    #[Test]
    public function it_stops_an_svg_over_its_own_cap_during_the_transfer(): void
    {
        $service = $this->service(['svg' => ['max_file_size' => 200000]]);

        $this->expectException(FileTooLargeException::class);
        $this->expectExceptionMessage('SVG file is too large (max 200000 bytes)');
        $service->fromUrl(self::$server->url('/svg?padding=5000000'));
    }

    #[Test]
    public function it_stops_a_body_without_a_length_at_the_cap(): void
    {
        $service = $this->service(['max_download_bytes' => 262144]);

        $this->expectException(FileTooLargeException::class);
        $this->expectExceptionMessage('max 262144 bytes');
        $service->fromUrl(self::$server->url('/stream?size=50000000'));
    }

    #[Test]
    public function the_timeout_is_a_deadline_for_slow_headers(): void
    {
        $this->assertTimesOut('/slow-headers');
    }

    #[Test]
    public function the_timeout_is_a_deadline_for_a_slow_body(): void
    {
        $this->assertTimesOut('/slow-body');
    }

    private function assertTimesOut(string $path): void
    {
        $service = $this->service(['http' => ['timeout' => 1]]);
        $started = microtime(true);

        try {
            $service->fromUrl(self::$server->url($path));
            $this->fail('Expected a UrlAccessException.');
        } catch (UrlAccessException $e) {
            $this->assertStringContainsString('Could not open URL', $e->getMessage());
        }

        $elapsed = microtime(true) - $started;
        $this->assertGreaterThanOrEqual(0.9, $elapsed);
        $this->assertLessThan(2.5, $elapsed, 'The timeout must bound the whole transfer.');
    }

    #[Test]
    public function it_measures_the_redirect_target_not_the_redirect_body(): void
    {
        $this->assertDimensions(33, 44, $this->service()->fromUrl(self::$server->url('/redirect')));
    }

    #[Test]
    public function an_empty_body_after_a_redirect_is_not_mistaken_for_the_redirect_body(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('File is empty');
        $this->service()->fromUrl(self::$server->url('/redirect?to=/empty'));
    }

    #[Test]
    public function it_does_not_decompress_the_body(): void
    {
        // ~50 KB compressed, 50 MB decompressed. Accept-Encoding: identity is
        // sent, but the server compresses anyway; the compressed bytes are not
        // an image.
        $service = $this->service(['max_download_bytes' => 16777216]);

        try {
            $service->fromUrl(self::$server->url('/gzip?size=50000000'));
            $this->fail('Expected an InvalidImageException.');
        } catch (FileTooLargeException $e) {
            $this->fail('The body must not be decompressed: '.$e->getMessage());
        } catch (InvalidImageException $e) {
            $this->assertStringContainsString('Could not determine image dimensions', $e->getMessage());
        }
    }

    #[Test]
    public function it_asks_for_an_uncompressed_body(): void
    {
        $seen = null;
        Http::globalRequestMiddleware(function ($request) use (&$seen) {
            $seen = $request->getHeaderLine('Accept-Encoding');

            return $request;
        });

        $this->service()->fromUrl(self::$server->url('/png'));

        $this->assertSame('identity', $seen);
    }

    #[Test]
    public function it_fetches_a_url_with_spaces_and_non_ascii_characters(): void
    {
        $this->assertDimensions(5, 6, $this->service()->fromUrl(self::$server->url('/png?w=5&h=6&name=a b ü#top')));
    }

    #[Test]
    public function it_reports_an_http_error_status(): void
    {
        $this->expectException(UrlAccessException::class);
        $this->expectExceptionMessage('(HTTP 404)');
        $this->service()->fromUrl(self::$server->url('/missing'));
    }

    #[Test]
    public function it_leaves_no_temporary_file_behind(): void
    {
        $service = $this->service(['temp_dir' => $this->tempPath]);

        $service->fromUrl(self::$server->url('/png-with-tail'));
        $service->fromUrl(self::$server->url('/png'));

        try {
            $service->fromUrl(self::$server->url('/slow-body'));
        } catch (UrlAccessException) {
        }

        $this->assertSame([], glob($this->tempPath.DIRECTORY_SEPARATOR.'imgdim_*'));
    }
}
