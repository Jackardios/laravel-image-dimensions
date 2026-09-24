<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Closure;
use Illuminate\Support\Facades\Http;
use Jackardios\ImageDimensions\Contracts\ImageDimensions as ImageDimensionsContract;
use Jackardios\ImageDimensions\Exceptions\UrlAccessException;
use Jackardios\ImageDimensions\Exceptions\UrlNotAllowedException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Support\UrlGuard;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * The rest of the suite relaxes the SSRF guard (see TestCase::defineEnvironment())
 * so that pipeline tests stay network-free. These tests deliberately exercise
 * the defaults the package actually ships with.
 */
class SecureDefaultsTest extends TestCase
{
    #[Test]
    public function the_shipped_config_defaults_to_blocking_private_hosts(): void
    {
        $config = require __DIR__.'/../../config/image-dimensions.php';

        $this->assertFalse(
            $config['url']['allow_private_hosts'],
            'the shipped config must block private hosts by default'
        );
        $this->assertSame(5, $config['url']['max_redirects']);
        $this->assertSame([], $config['url']['allowed_hosts']);
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

        // Drop the instance so it is rebuilt from the config above.
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

        $this->assertFalse($options['decode_content'], 'the download cap must count the bytes received');
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
    public function a_literal_address_is_judged_without_dns(): void
    {
        $guard = new UrlGuard(allowPrivateHosts: false, resolver: function () {
            $this->fail('No DNS lookup expected for a literal address.');
        });

        $this->expectException(UrlNotAllowedException::class);
        $guard->assertAllowed('http://10.0.0.1/x.png');
    }

    /**
     * Regression: fromUrl() resolved the host before looking in the cache,
     * so every cache hit cost two DNS queries and failed while DNS was down.
     */
    #[Test]
    public function a_cache_hit_needs_no_dns(): void
    {
        $lookups = 0;
        $service = $this->serviceResolvingWith(function (string $host) use (&$lookups) {
            $lookups++;

            return $lookups === 1 ? ['93.184.215.14'] : [];
        });

        Http::fake(['https://example.com/*' => Http::response($this->imageBytes(3, 4))]);

        $this->assertDimensions(3, 4, $service->fromUrl('https://example.com/a.png'));
        $this->assertDimensions(3, 4, $service->fromUrl('https://example.com/a.png'));
        $this->assertSame(1, $lookups);
    }

    #[Test]
    public function a_fetch_is_refused_when_the_host_now_resolves_to_a_private_address(): void
    {
        $service = $this->serviceResolvingWith(fn (string $host) => ['169.254.169.254']);
        Http::fake();

        try {
            $service->fromUrl('https://example.com/a.png');
            $this->fail('Expected a UrlNotAllowedException.');
        } catch (UrlNotAllowedException) {
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function the_scheme_may_be_upper_case(): void
    {
        $service = $this->serviceResolvingWith(fn (string $host) => ['93.184.215.14']);
        Http::fake(['https://example.com/*' => Http::response($this->imageBytes(3, 4))]);

        $this->assertDimensions(3, 4, $service->fromUrl('HTTPS://example.com/a.png'));
    }

    /**
     * The guard must judge the host the request actually goes to: an
     * internationalized name is checked, cached and fetched as punycode.
     */
    #[Test]
    public function the_guard_checks_the_host_that_is_fetched(): void
    {
        $resolved = [];
        $service = $this->serviceResolvingWith(function (string $host) use (&$resolved) {
            $resolved[] = $host;

            return ['93.184.215.14'];
        });
        Http::fake(['https://xn--e1afmkfd.xn--p1ai/*' => Http::response($this->imageBytes(3, 4))]);

        $this->assertDimensions(3, 4, $service->fromUrl('https://Пример.рф/картинка.png'));

        $this->assertSame(['xn--e1afmkfd.xn--p1ai'], $resolved);
        Http::assertSent(fn ($request) => $request->url() === 'https://xn--e1afmkfd.xn--p1ai/%D0%BA%D0%B0%D1%80%D1%82%D0%B8%D0%BD%D0%BA%D0%B0.png');
    }

    #[Test]
    public function spellings_of_one_url_share_a_cache_entry(): void
    {
        $service = $this->serviceResolvingWith(fn (string $host) => ['93.184.215.14']);
        Http::fake(['https://example.com/*' => Http::response($this->imageBytes(3, 4))]);

        foreach (['https://example.com/a b.png', 'https://example.com/a%20b.png#top', 'HTTPS://EXAMPLE.COM:443/a b.png'] as $url) {
            $this->assertDimensions(3, 4, $service->fromUrl($url));
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_host_may_contain_underscores(): void
    {
        $service = $this->serviceResolvingWith(fn (string $host) => ['93.184.215.14']);
        Http::fake(['https://my_bucket.s3.amazonaws.com/*' => Http::response($this->imageBytes(3, 4))]);

        $this->assertDimensions(3, 4, $service->fromUrl('https://my_bucket.s3.amazonaws.com/a.png'));
    }

    /**
     * The SSRF guard with its defaults, but with a fake resolver so that
     * no test depends on real DNS.
     *
     * @param  Closure(string): list<string>  $resolver
     */
    private function serviceResolvingWith(Closure $resolver): ImageDimensionsService
    {
        return new class($resolver) extends ImageDimensionsService
        {
            public function __construct(Closure $resolver)
            {
                parent::__construct(['cache_ttl' => 3600]);
                $this->urlGuard = new UrlGuard(resolver: $resolver);
            }
        };
    }
}
