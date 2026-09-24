<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Unit;

use Jackardios\ImageDimensions\Exceptions\TemporaryFileException;
use Jackardios\ImageDimensions\Support\TemporaryFile;
use Jackardios\ImageDimensions\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TemporaryFileTest extends TestCase
{
    #[Test]
    public function it_creates_a_file_and_removes_it_on_destruction(): void
    {
        $temp = new TemporaryFile($this->tempPath);
        $path = $temp->path();

        $this->assertFileExists($path);

        unset($temp);

        $this->assertFileDoesNotExist($path);
    }

    #[Test]
    public function it_appends_from_a_stream_up_to_the_byte_limit(): void
    {
        $temp = new TemporaryFile($this->tempPath);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, str_repeat('a', 100));
        rewind($stream);

        $written = $temp->appendFromStream($stream, 40);
        fclose($stream);

        $this->assertSame(40, $written);
        $this->assertSame(40, $temp->bytesWritten());
        $this->assertSame(str_repeat('a', 40), file_get_contents($temp->path()));
    }

    #[Test]
    public function it_continues_appending_to_the_same_file(): void
    {
        $temp = new TemporaryFile($this->tempPath);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'hello world');
        rewind($stream);

        $temp->appendFromStream($stream, 5);
        $temp->appendFromStream($stream, 100);
        fclose($stream);

        $this->assertSame('hello world', file_get_contents($temp->path()));
        $this->assertSame(11, $temp->bytesWritten());
    }

    #[Test]
    public function it_reports_zero_bytes_for_an_empty_stream_without_throwing(): void
    {
        $temp = new TemporaryFile($this->tempPath);

        $stream = fopen('php://memory', 'r+');
        rewind($stream);

        $written = $temp->appendFromStream($stream, 100);
        fclose($stream);

        $this->assertSame(0, $written);
    }

    #[Test]
    public function it_throws_when_the_directory_is_not_writable(): void
    {
        $directory = $this->createReadOnlyDirectory('read-only');

        $this->expectException(TemporaryFileException::class);
        new TemporaryFile($directory);
    }

    #[Test]
    public function it_throws_when_the_directory_does_not_exist(): void
    {
        $this->expectException(TemporaryFileException::class);
        new TemporaryFile($this->tempPath.DIRECTORY_SEPARATOR.'missing');
    }

    /**
     * tempnam() keeps three characters of the prefix on Windows.
     */
    #[Test]
    public function it_creates_the_file_in_the_given_directory_with_the_whole_prefix(): void
    {
        $temp = new TemporaryFile($this->tempPath.DIRECTORY_SEPARATOR, 'imgdim_storage_');

        $this->assertSame(realpath($this->tempPath), realpath(dirname($temp->path())));
        $this->assertStringStartsWith('imgdim_storage_', basename($temp->path()));
        $this->assertNotSame($temp->path(), (new TemporaryFile($this->tempPath, 'imgdim_storage_'))->path());
    }

    #[Test]
    public function only_the_owner_may_read_the_file(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permissions do not apply on Windows.');
        }

        $previous = umask(0022);

        try {
            $temp = new TemporaryFile($this->tempPath);
        } finally {
            umask($previous);
        }

        $this->assertSame(0600, fileperms($temp->path()) & 0777);
    }

    /**
     * Windows ignores the read-only attribute on directories, but
     * is_writable() does not.
     */
    #[Test]
    public function it_uses_a_windows_directory_marked_read_only(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('The read-only attribute of directories is a Windows feature.');
        }

        $directory = $this->tempPath.DIRECTORY_SEPARATOR.'marked';
        mkdir($directory);
        exec('attrib +R '.escapeshellarg($directory), $output, $status);
        $this->assertSame(0, $status);

        try {
            $temp = new TemporaryFile($directory);
            $this->assertSame(realpath($directory), realpath(dirname($temp->path())));
            $temp->delete();
        } finally {
            exec('attrib -R '.escapeshellarg($directory));
        }
    }
}
