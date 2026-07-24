<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Jackardios\ImageDimensions\Contracts\ImageDimensions as ImageDimensionsContract;
use Jackardios\ImageDimensions\Exceptions\UrlAccessException;
use Jackardios\ImageDimensions\Exceptions\UrlNotAllowedException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Support\UrlGuard;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * The rest of the suite relaxes the SSRF guard (via the
 * IMAGE_DIMENSIONS_URL_ALLOW_PRIVATE_HOSTS env var in phpunit.xml) so that
 * pipeline tests stay network-free. These tests deliberately exercise the
 * defaults the package actually ships with.
 */
class SecureDefaultsTest extends TestCase
{
    #[Test]
    public function the_shipped_config_defaults_to_blocking_private_hosts(): void
    {
        // phpunit.xml sets this var to keep the rest of the suite network-free;
        // clear it so the config file's own default is what gets evaluated.
        $restore = $_ENV['IMAGE_DIMENSIONS_URL_ALLOW_PRIVATE_HOSTS'] ?? null;
        unset($_ENV['IMAGE_DIMENSIONS_URL_ALLOW_PRIVATE_HOSTS'], $_SERVER['IMAGE_DIMENSIONS_URL_ALLOW_PRIVATE_HOSTS']);
        putenv('IMAGE_DIMENSIONS_URL_ALLOW_PRIVATE_HOSTS');

        try {
            $config = require __DIR__.'/../../config/image-dimensions.php';

            $this->assertFalse(
                $config['url']['allow_private_hosts'],
                'the shipped config must block private hosts by default'
            );
            $this->assertSame(5, $config['url']['max_redirects']);
            $this->assertSame([], $config['url']['allowed_hosts']);
        } finally {
            if ($restore !== null) {
                $_ENV['IMAGE_DIMENSIONS_URL_ALLOW_PRIVATE_HOSTS'] = $restore;
                $_SERVER['IMAGE_DIMENSIONS_URL_ALLOW_PRIVATE_HOSTS'] = $restore;
                putenv('IMAGE_DIMENSIONS_URL_ALLOW_PRIVATE_HOSTS='.$restore);
            }
        }
    }

    /**
     * If the published config is missing or stale (no `url` block at all), the
     * code default must still be the secure one.
     */
    #[Test]
    public function the_code_default_blocks_private_hosts_when_config_is_absent(): void
    {
        $service = new ImageDimensionsService(['enable_cache' => false]);

        $this->expectException(UrlNotAllowedException::class);
        $service->fromUrl('http://169.254.169.254/latest/meta-data/');
    }

    #[Test]
    public function a_container_resolved_service_blocks_private_hosts_when_configured_securely(): void
    {
        config()->set('image-dimensions.url.allow_private_hosts', false);
        config()->set('image-dimensions.enable_cache', false);

        // Drop the singleton so it is rebuilt from the config above.
        $this->app->forgetInstance(ImageDimensionsService::class);

        $service = $this->app->make(ImageDimensionsContract::class);

        $this->expectException(UrlNotAllowedException::class);
        $service->fromUrl('http://169.254.169.254/latest/meta-data/');
    }

    #[Test]
    public function a_blocked_url_is_also_catchable_as_a_url_access_exception(): void
    {
        $service = new ImageDimensionsService([
            'enable_cache' => false,
            'url' => ['allow_private_hosts' => false],
        ]);

        $this->expectException(UrlAccessException::class);
        $service->fromUrl('http://127.0.0.1/internal.png');
    }

    /**
     * Http::fake() short-circuits Guzzle's redirect middleware, so the
     * allow_redirects option block is never executed by the pipeline tests. Assert
     * its shape directly — a renamed key or a missing on_redirect callback would
     * otherwise disable redirect re-validation silently.
     */
    #[Test]
    public function the_request_options_wire_up_redirect_revalidation(): void
    {
        $service = new ImageDimensionsService([
            'url' => ['max_redirects' => 3],
            'http' => ['timeout' => 15, 'connect_timeout' => 4, 'verify_ssl' => true],
        ]);

        $method = new ReflectionMethod($service, 'requestOptions');
        /** @var array<string, mixed> $options */
        $options = $method->invoke($service);

        $this->assertTrue($options['stream'], 'the body must be streamed, not buffered');
        $this->assertSame(15, $options['timeout']);
        $this->assertSame(4, $options['connect_timeout']);
        $this->assertTrue($options['verify']);

        $this->assertArrayHasKey('allow_redirects', $options);
        $redirects = $options['allow_redirects'];

        $this->assertSame(3, $redirects['max']);
        $this->assertTrue($redirects['strict']);
        $this->assertFalse($redirects['referer']);
        $this->assertSame(['http', 'https'], $redirects['protocols']);
        $this->assertIsCallable($redirects['on_redirect']);
    }

    #[Test]
    public function the_redirect_callback_rejects_a_hop_into_a_private_network(): void
    {
        $service = new ImageDimensionsService(['url' => ['allow_private_hosts' => false]]);

        $method = new ReflectionMethod($service, 'requestOptions');
        /** @var array<string, mixed> $options */
        $options = $method->invoke($service);
        $onRedirect = $options['allow_redirects']['on_redirect'];

        $this->expectException(UrlNotAllowedException::class);
        $onRedirect(null, null, 'http://169.254.169.254/latest/meta-data/');
    }

    #[Test]
    public function the_guard_memoizes_resolution_for_literal_addresses_without_dns(): void
    {
        $guard = new UrlGuard(allowPrivateHosts: false);

        // Literal IPs must never hit the resolver, so this stays network-free.
        $this->expectException(UrlNotAllowedException::class);
        $guard->assertAllowed('http://10.0.0.1/x.png');
    }
}
