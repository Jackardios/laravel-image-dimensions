<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Unit;

use Jackardios\ImageDimensions\Support\UrlRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UrlRedactorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function urlProvider(): array
    {
        return [
            'nothing secret' => ['https://example.com/a.png', 'https://example.com/a.png'],
            'user and password' => ['https://user:secret@example.com/a.png', 'https://***@example.com/a.png'],
            'token as user' => ['https://ghp_token@example.com/', 'https://***@example.com/'],
            'at sign in the password' => ['https://user:p@ss@example.com:8080/a.png', 'https://***@example.com:8080/a.png'],
            'at sign in the path' => ['https://example.com/users/@me.png', 'https://example.com/users/@me.png'],
            'signed URL' => [
                'https://bucket.s3.amazonaws.com/a.png?X-Amz-Credential=AKIA%2F&X-Amz-Signature=abc123',
                'https://bucket.s3.amazonaws.com/a.png?X-Amz-Credential=***&X-Amz-Signature=***',
            ],
            'bare token query' => ['https://example.com/a.png?s3cr3t', 'https://example.com/a.png?***'],
            'empty query' => ['https://example.com/a.png?', 'https://example.com/a.png?'],
            'empty parameters' => ['https://example.com/a.png?a=1&&b', 'https://example.com/a.png?a=***&&***'],
            'fragment' => ['https://example.com/a.png#access_token=abc', 'https://example.com/a.png'],
            'no scheme' => ['//user:secret@example.com/a.png?t=1', '//***@example.com/a.png?t=***'],
            'invalid URL' => ['https://user:secret@exa mple.com/a b.png?t=x y', 'https://***@exa mple.com/a b.png?t=***'],
            'not a URL' => ['not a url', 'not a url'],
        ];
    }

    #[Test]
    #[DataProvider('urlProvider')]
    public function it_redacts_secrets(string $url, string $expected): void
    {
        $this->assertSame($expected, UrlRedactor::redact($url));
    }
}
