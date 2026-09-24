<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Support;

/**
 * A stream wrapper that serves registered contents and counts the bytes read
 * from it. Without stream_seek() its streams cannot seek, like a socket.
 */
final class CountingStream
{
    private const SCHEME = 'imgdim-counting';

    /** @var array<string, string> */
    private static array $contents = [];

    /** @var array<string, int> */
    private static array $bytesRead = [];

    /** @var resource|null */
    public $context;

    private string $name = '';

    private int $position = 0;

    /**
     * @return resource
     */
    public static function open(string $contents)
    {
        if (! in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }

        $name = bin2hex(random_bytes(8));
        self::$contents[$name] = $contents;
        self::$bytesRead[$name] = 0;

        $stream = fopen(self::SCHEME.'://'.$name, 'rb');
        assert(is_resource($stream));

        return $stream;
    }

    /**
     * @param  resource  $stream
     */
    public static function bytesRead($stream): int
    {
        return self::$bytesRead[substr(stream_get_meta_data($stream)['uri'], strlen(self::SCHEME) + 3)];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->name = substr($path, strlen(self::SCHEME) + 3);

        return isset(self::$contents[$this->name]);
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$contents[$this->name], $this->position, $count);
        $this->position += strlen($chunk);
        self::$bytesRead[$this->name] += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$contents[$this->name]);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => strlen(self::$contents[$this->name])];
    }
}
