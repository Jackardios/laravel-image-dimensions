<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Feature;

use Jackardios\ImageDimensions\Exceptions\UrlNotAllowedException;
use Jackardios\ImageDimensions\ImageDimensionsService;
use Jackardios\ImageDimensions\Support\UrlGuard;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Settings mostly come from environment variables, so as strings: an empty
 * variable arrives as '', and booleans may be spelled "off" or "no".
 */
class ConfigValuesTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: mixed}>
     */
    public static function settingProvider(): array
    {
        return [
            // An empty value used to mean 0, i.e. no limit at all.
            'empty download cap' => [['max_download_bytes' => ''], 'maxDownloadBytes', 33554432],
            'non-numeric download cap' => [['max_download_bytes' => 'lots'], 'maxDownloadBytes', 33554432],
            'numeric download cap' => [['max_download_bytes' => '1000000'], 'maxDownloadBytes', 1000000],
            'download cap in exponent notation' => [['max_download_bytes' => '1e6'], 'maxDownloadBytes', 1000000],
            'download cap turned off' => [['max_download_bytes' => '0'], 'maxDownloadBytes', 0],
            'huge download cap' => [['max_download_bytes' => '1e30'], 'maxDownloadBytes', 1000000000000000],
            'empty read size' => [['remote_read_bytes' => ''], 'remoteReadBytes', 131072],
            'read size below the minimum' => [['remote_read_bytes' => 100], 'remoteReadBytes', 8192],
            'read size above the maximum' => [['remote_read_bytes' => 10000000], 'remoteReadBytes', 1048576],
            // The header must fit under the download cap.
            'download cap below the read size' => [['remote_read_bytes' => 65536, 'max_download_bytes' => 1000], 'maxDownloadBytes', 65536],
            'negative download cap' => [['max_download_bytes' => -5], 'maxDownloadBytes', 0],
            // An empty value used to turn caching off.
            'empty TTL' => [['cache_ttl' => ''], 'cacheTtl', 3600],
            'TTL of null' => [['cache_ttl' => null], 'cacheTtl', null],
            'TTL of 0' => [['cache_ttl' => '0'], 'cacheTtl', 0],
            'numeric TTL' => [['cache_ttl' => '600'], 'cacheTtl', 600],
            'cache off' => [['enable_cache' => 'off'], 'enableCache', false],
            'cache "no"' => [['enable_cache' => 'no'], 'enableCache', false],
            'cache "0"' => [['enable_cache' => '0'], 'enableCache', false],
            'cache "yes"' => [['enable_cache' => 'yes'], 'enableCache', true],
            'empty cache flag' => [['enable_cache' => ''], 'enableCache', true],
            'unrecognized cache flag' => [['enable_cache' => 'maybe'], 'enableCache', true],
            'empty SVG cap' => [['svg' => ['max_file_size' => '']], 'svgMaxFileSize', 10485760],
            'temp dir of null' => [['temp_dir' => null], 'tempDir', sys_get_temp_dir()],
            'empty temp dir' => [['temp_dir' => ''], 'tempDir', sys_get_temp_dir()],
            'temp dir' => [['temp_dir' => '/srv/tmp'], 'tempDir', '/srv/tmp'],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[Test]
    #[DataProvider('settingProvider')]
    public function it_reads_settings_as_the_environment_spells_them(array $config, string $property, mixed $expected): void
    {
        $service = new ImageDimensionsService($config);

        $this->assertSame($expected, (fn () => $this->{$property})->call($service));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function httpSettingProvider(): array
    {
        return [
            'defaults' => [[], ['timeout' => 60, 'connect_timeout' => 10, 'verify' => true]],
            'empty values' => [['timeout' => '', 'connect_timeout' => '', 'verify_ssl' => ''], ['timeout' => 60, 'connect_timeout' => 10, 'verify' => true]],
            // A fraction used to be cut to 0, which cURL takes as no timeout.
            'fractions of a second' => [['timeout' => '2.5', 'connect_timeout' => 0.5], ['timeout' => 2.5, 'connect_timeout' => 0.5, 'verify' => true]],
            'negative' => [['timeout' => -1, 'connect_timeout' => '-3'], ['timeout' => 0, 'connect_timeout' => 0, 'verify' => true]],
            'verification off' => [['verify_ssl' => 'off'], ['timeout' => 60, 'connect_timeout' => 10, 'verify' => false]],
            'verification "false"' => [['verify_ssl' => 'false'], ['timeout' => 60, 'connect_timeout' => 10, 'verify' => false]],
        ];
    }

    /**
     * @param  array<string, mixed>  $http
     * @param  array<string, mixed>  $expected
     */
    #[Test]
    #[DataProvider('httpSettingProvider')]
    public function it_reads_http_settings(array $http, array $expected): void
    {
        $service = new ImageDimensionsService(['http' => $http]);

        $this->assertSame($expected, (fn () => $this->httpOptions)->call($service));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function privateHostsSettingProvider(): array
    {
        return [
            // A plain cast turned these into true, allowing private hosts.
            'off' => ['off', false],
            'no' => ['no', false],
            'empty' => ['', false],
            'unrecognized' => ['sometimes', false],
            'on' => ['on', true],
            'true' => ['true', true],
            '1' => ['1', true],
        ];
    }

    #[Test]
    #[DataProvider('privateHostsSettingProvider')]
    public function it_reads_the_private_hosts_switch(mixed $value, bool $allowed): void
    {
        $guard = $this->guard(['url' => ['allow_private_hosts' => $value]]);

        if (! $allowed) {
            $this->expectException(UrlNotAllowedException::class);
        }

        $guard->assertAllowed('http://127.0.0.1/a.png');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function allowed_hosts_may_be_a_comma_separated_string(): void
    {
        // An allowlist given as a string used to be ignored: every host was allowed.
        $guard = $this->guard(['url' => ['allow_private_hosts' => true, 'allowed_hosts' => 'cdn.example.com, img.example.com']]);

        $guard->assertAllowed('https://cdn.example.com/a.png');
        $guard->assertAllowed('https://img.example.com/a.png');

        $this->expectException(UrlNotAllowedException::class);
        $guard->assertAllowed('https://evil.example.com/a.png');
    }

    #[Test]
    public function an_empty_redirect_limit_gives_the_default(): void
    {
        $this->assertSame(5, $this->guard(['url' => ['max_redirects' => '']])->maxRedirects());
        $this->assertSame(2, $this->guard(['url' => ['max_redirects' => '2']])->maxRedirects());
    }

    /**
     * The shipped config file, read with empty and spelled-out variables.
     */
    #[Test]
    public function the_config_file_survives_empty_environment_variables(): void
    {
        $variables = [
            'IMAGE_DIMENSIONS_MAX_DOWNLOAD_BYTES' => '',
            'IMAGE_DIMENSIONS_CACHE_TTL' => '',
            'IMAGE_DIMENSIONS_TEMP_DIR' => '',
            'IMAGE_DIMENSIONS_HTTP_TIMEOUT' => '',
            'IMAGE_DIMENSIONS_HTTP_VERIFY_SSL' => '',
            'IMAGE_DIMENSIONS_URL_ALLOW_PRIVATE_HOSTS' => 'off',
            'IMAGE_DIMENSIONS_URL_ALLOWED_HOSTS' => 'cdn.example.com,',
        ];

        foreach ($variables as $name => $value) {
            putenv("{$name}={$value}");
        }

        try {
            $service = new ImageDimensionsService(require dirname(__DIR__, 2).'/config/image-dimensions.php');
        } finally {
            foreach (array_keys($variables) as $name) {
                putenv($name);
            }
        }

        $read = fn (string $property) => (fn () => $this->{$property})->call($service);

        $this->assertSame(33554432, $read('maxDownloadBytes'));
        $this->assertSame(3600, $read('cacheTtl'));
        $this->assertSame(sys_get_temp_dir(), $read('tempDir'));
        $this->assertSame(['timeout' => 60, 'connect_timeout' => 10, 'verify' => true], $read('httpOptions'));

        $guard = $read('urlGuard');
        $this->assertInstanceOf(UrlGuard::class, $guard);
        $this->expectException(UrlNotAllowedException::class);
        $this->expectExceptionMessage('is not in the configured allowlist');
        $guard->assertAllowed('https://evil.example.com/a.png');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function guard(array $config): UrlGuard
    {
        return (fn () => $this->urlGuard)->call(new ImageDimensionsService($config));
    }
}
