<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Support;

use Jackardios\ImageDimensions\Exceptions\TemporaryFileException;

/**
 * A self-cleaning temporary file.
 *
 * The underlying file is created on construction and removed by delete() or
 * when the object is destroyed. A file that cannot be removed then (still open
 * elsewhere, which Windows does not allow) is removed at shutdown, and so is
 * any file whose object never got destroyed because a fatal error ended the
 * request. A single write handle is kept open across appends, allowing a
 * partial read to be extended with more data without reopening the file.
 */
final class TemporaryFile
{
    /**
     * Files not removed yet, keyed by path.
     *
     * @var array<string, true>
     */
    private static array $pending = [];

    private static bool $shutdownRegistered = false;

    private string $path = '';

    /** @var resource|null */
    private $handle;

    private int $bytesWritten = 0;

    /**
     * @throws TemporaryFileException
     */
    public function __construct(string $directory, string $prefix = 'imgdim_')
    {
        // Neither is_writable() nor tempnam(). On Windows, is_writable() is
        // false for a writable directory with the read-only attribute, and
        // tempnam() keeps three characters of the prefix. Everywhere, tempnam()
        // silently falls back to the system temp directory when it cannot
        // create the file in the one it was given; fopen() fails instead.
        $base = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$prefix;
        $handle = false;
        for ($attempt = 0; $attempt < 3 && $handle === false; $attempt++) {
            $path = $base.bin2hex(random_bytes(8));
            // "x": create the file, never open an existing one.
            $handle = @fopen($path, 'xb');
        }

        if ($handle === false) {
            throw TemporaryFileException::couldNotCreate();
        }

        // Owner only, as tempnam() made it: the contents are downloads.
        @chmod($path, 0600);

        $this->path = $path;
        $this->handle = $handle;

        self::$pending[$path] = true;
        if (! self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                foreach (array_keys(self::$pending) as $pending) {
                    @unlink($pending);
                }
            });
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function bytesWritten(): int
    {
        return $this->bytesWritten;
    }

    /**
     * Append up to $maxBytes bytes from a stream, returning the number of bytes
     * actually written during this call: fewer once the stream ends or stays
     * silent (see StreamReader::read()).
     *
     * @param  resource  $stream
     *
     * @throws TemporaryFileException
     */
    public function appendFromStream($stream, int $maxBytes): int
    {
        $written = 0;

        while ($written < $maxBytes) {
            $chunk = StreamReader::read($stream, min(1048576, $maxBytes - $written));
            if ($chunk === '') {
                break;
            }

            $this->append($chunk);
            $written += strlen($chunk);
        }

        return $written;
    }

    /**
     * Append a raw string to the file.
     *
     * @throws TemporaryFileException
     */
    public function append(string $data): void
    {
        if (! is_resource($this->handle)) {
            throw TemporaryFileException::couldNotWrite();
        }

        $bytes = @fwrite($this->handle, $data);
        if ($bytes === false || $bytes < strlen($data)) {
            throw TemporaryFileException::couldNotWrite();
        }

        $this->bytesWritten += $bytes;
        $this->flush();
    }

    /**
     * Empty the file, e.g. before the body of the next response in a
     * redirect chain is written into it.
     *
     * @throws TemporaryFileException
     */
    public function truncate(): void
    {
        if (! is_resource($this->handle) || ! @ftruncate($this->handle, 0) || ! @rewind($this->handle)) {
            throw TemporaryFileException::couldNotWrite();
        }

        $this->bytesWritten = 0;
    }

    /**
     * Flush buffered writes so the file on disk reflects everything written.
     */
    public function flush(): void
    {
        if (is_resource($this->handle)) {
            @fflush($this->handle);
        }
    }

    /**
     * Close and remove the file now, rather than when the object is
     * destroyed: callbacks handed to an HTTP client can keep it alive long
     * after the request.
     */
    public function delete(): void
    {
        if (is_resource($this->handle)) {
            @fclose($this->handle);
        }

        $this->handle = null;

        if ($this->path !== '' && (@unlink($this->path) || ! file_exists($this->path))) {
            unset(self::$pending[$this->path]);
        }
    }

    public function __destruct()
    {
        $this->delete();
    }
}
