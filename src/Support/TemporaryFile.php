<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Support;

use Jackardios\ImageDimensions\Exceptions\TemporaryFileException;

/**
 * A self-cleaning temporary file.
 *
 * The underlying file is created on construction and removed when the object is
 * destroyed, so callers do not need finally-blocks to avoid leaking temp files.
 * A single write handle is kept open across appends, allowing a partial read to
 * be extended with more data without reopening the file.
 */
final class TemporaryFile
{
    private string $path;

    /** @var resource|null */
    private $handle;

    private int $bytesWritten = 0;

    /**
     * @throws TemporaryFileException
     */
    public function __construct(string $directory, string $prefix = 'imgdim_')
    {
        if (!is_dir($directory) || !is_writable($directory)) {
            throw TemporaryFileException::couldNotCreate();
        }

        $path = false;
        for ($attempt = 0; $attempt < 3 && $path === false; $attempt++) {
            $path = @tempnam($directory, $prefix);
            if ($path === false) {
                usleep(10000); // 10ms
            }
        }

        if ($path === false) {
            throw TemporaryFileException::couldNotCreate();
        }

        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            @unlink($path);
            throw TemporaryFileException::couldNotCreate();
        }

        $this->path = $path;
        $this->handle = $handle;
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
     * actually written during this call. Reads in 8KB chunks and stops at EOF,
     * once $maxBytes is reached, or after repeated empty reads.
     *
     * @param resource $stream
     * @throws TemporaryFileException
     */
    public function appendFromStream($stream, int $maxBytes): int
    {
        if ($this->handle === null) {
            throw TemporaryFileException::couldNotWrite();
        }

        $written = 0;
        $emptyReads = 0;
        $maxEmptyReads = 3;

        while ($written < $maxBytes && !feof($stream) && $emptyReads < $maxEmptyReads) {
            $chunkSize = min(8192, $maxBytes - $written);
            $chunk = @fread($stream, $chunkSize);

            if ($chunk === false) {
                break;
            }

            if ($chunk === '') {
                $emptyReads++;
                usleep(1000);
                continue;
            }

            $emptyReads = 0;

            $bytes = @fwrite($this->handle, $chunk);
            if ($bytes === false) {
                throw TemporaryFileException::couldNotWrite();
            }

            $written += $bytes;
            $this->bytesWritten += $bytes;

            if ($bytes < strlen($chunk)) {
                break;
            }
        }

        $this->flush();

        return $written;
    }

    /**
     * Append a raw string to the file.
     *
     * @throws TemporaryFileException
     */
    public function append(string $data): void
    {
        if ($this->handle === null) {
            throw TemporaryFileException::couldNotWrite();
        }

        $bytes = @fwrite($this->handle, $data);
        if ($bytes === false) {
            throw TemporaryFileException::couldNotWrite();
        }

        $this->bytesWritten += $bytes;
        $this->flush();
    }

    /**
     * Flush buffered writes so the file on disk reflects everything written.
     */
    public function flush(): void
    {
        if ($this->handle !== null) {
            @fflush($this->handle);
        }
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            @fclose($this->handle);
            $this->handle = null;
        }

        if (isset($this->path) && file_exists($this->path)) {
            @unlink($this->path);
        }
    }
}
