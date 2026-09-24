<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Unit;

use Jackardios\ImageDimensions\Support\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Polyfill\Intl\Idn\Idn;

class UrlNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function urlProvider(): array
    {
        return [
            'unchanged' => ['https://example.com/a.png?w=1', 'https://example.com/a.png?w=1'],
            'internationalized host' => ['https://пример.рф/a.png', 'https://xn--e1afmkfd.xn--p1ai/a.png'],
            'no transitional mapping' => ['https://faß.de/a.png', 'https://xn--fa-hia.de/a.png'],
            'ideographic full stop' => ['https://example。com/a.png', 'https://example.com/a.png'],
            'underscore in the host' => ['https://my_bucket.s3.amazonaws.com/a.png', 'https://my_bucket.s3.amazonaws.com/a.png'],
            'upper case' => ['HTTPS://EXAMPLE.COM/A.png', 'https://example.com/A.png'],
            'space in the path and query' => ['https://example.com/a b.png?q=a b', 'https://example.com/a%20b.png?q=a%20b'],
            'already encoded' => ['https://example.com/a%20b.png', 'https://example.com/a%20b.png'],
            'non-ASCII path and query' => ['https://example.com/ü.png?q=ä', 'https://example.com/%C3%BC.png?q=%C3%A4'],
            'characters not allowed in a path' => ['https://example.com/a"<>{}.png', 'https://example.com/a%22%3C%3E%7B%7D.png'],
            'fragment' => ['https://example.com/a.png#top', 'https://example.com/a.png'],
            'default port' => ['https://example.com:443/a.png', 'https://example.com/a.png'],
            'other port' => ['http://example.com:8080/a.png', 'http://example.com:8080/a.png'],
            'user info' => ['https://user:p@ss@example.com/a.png', 'https://user:p%40ss@example.com/a.png'],
            'IPv4 address' => ['http://203.0.113.9/a.png', 'http://203.0.113.9/a.png'],
            'IPv6 address' => ['http://[2001:DB8::1]:8080/a.png', 'http://[2001:db8::1]:8080/a.png'],
            'IPv4-mapped IPv6 address' => ['http://[::ffff:1.2.3.4]/a.png', 'http://[::ffff:1.2.3.4]/a.png'],
            'surrounding whitespace' => [" \thttps://example.com/a.png\r\n", 'https://example.com/a.png'],
            'trailing dot' => ['https://example.com./a.png', 'https://example.com./a.png'],
        ];
    }

    #[Test]
    #[DataProvider('urlProvider')]
    public function it_normalizes_urls(string $url, string $expected): void
    {
        $this->assertSame($expected, UrlNormalizer::normalize($url));
        $this->assertSame($expected, UrlNormalizer::normalize($expected), 'Normalizing must be idempotent.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidUrlProvider(): array
    {
        return [
            'empty' => [''],
            'no scheme' => ['example.com/a.png'],
            'no host' => ['http:///a.png'],
            'relative' => ['/a.png'],
            'space in the host' => ['https://exa mple.com/a.png'],
            'empty label' => ['https://example..com/a.png'],
            'leading hyphen' => ['https://-example.com/a.png'],
            'trailing hyphen' => ['https://example-.com/a.png'],
            'percent-encoded host' => ['https://%31%32%37.0.0.1/a.png'],
            'label too long' => ['https://'.str_repeat('a', 64).'.com/'],
            'host too long' => ['https://'.str_repeat('a.', 127).'com/'],
            'joiner out of context' => ["https://a\u{200D}b.com/"],
            'left-to-right and right-to-left in one label' => ["https://a\u{05D0}.com/"],
            'port out of range' => ['https://example.com:99999/a.png'],
            'unclosed IPv6 literal' => ['http://[::1/a.png'],
            'not an IPv6 address' => ['http://[example.com]/a.png'],
            'line break inside' => ["https://example.com/a\nb.png"],
            'NUL inside' => ["https://example.com/a\0.png"],
        ];
    }

    #[Test]
    #[DataProvider('invalidUrlProvider')]
    public function it_rejects_invalid_urls(string $url): void
    {
        $this->assertNull(UrlNormalizer::normalize($url));
    }

    /**
     * Allowlist entries are normalized with normalizeHost() alone.
     */
    #[Test]
    public function it_normalizes_a_host_on_its_own(): void
    {
        $this->assertSame('xn--e1afmkfd.xn--p1ai', UrlNormalizer::normalizeHost('Пример.РФ'));
        $this->assertSame('[2001:db8::1]', UrlNormalizer::normalizeHost('[2001:DB8::1]'));
        $this->assertSame('[::ffff:1.2.3.4]', UrlNormalizer::normalizeHost('[::ffff:1.2.3.4]'));

        foreach (['[example.com]', '[::1', '[::1]x', '[]', 'exa mple.com'] as $invalid) {
            $this->assertNull(UrlNormalizer::normalizeHost($invalid), $invalid);
        }
    }

    #[Test]
    public function hosts_at_the_length_limits_are_valid(): void
    {
        $this->assertNotNull(UrlNormalizer::normalizeHost(str_repeat('a', 63).'.com'));
        $this->assertNotNull(UrlNormalizer::normalizeHost(str_repeat('a.', 125).'com'));
        $this->assertNotNull(UrlNormalizer::normalizeHost(str_repeat('a.', 125).'com.'));
        $this->assertNull(UrlNormalizer::normalizeHost(str_repeat('a.', 126).'co'));
    }

    /**
     * Without ext-intl, symfony/polyfill-intl-idn provides idn_to_ascii().
     */
    #[Test]
    public function the_polyfill_agrees_with_ext_intl(): void
    {
        if (! extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl is not loaded.');
        }

        $options = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;

        foreach (['пример.рф', 'faß.de', 'example。com', 'ÖBB.at', 'münchen.de', "a\u{200D}b.com", 'مثال.إختبار', "a\u{05D0}.com", 'a..b'] as $host) {
            $this->assertSame(
                idn_to_ascii($host, $options, INTL_IDNA_VARIANT_UTS46),
                Idn::idn_to_ascii($host, $options, Idn::INTL_IDNA_VARIANT_UTS46),
                $host,
            );
        }
    }
}
