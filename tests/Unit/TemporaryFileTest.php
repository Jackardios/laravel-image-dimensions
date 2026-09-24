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
}
