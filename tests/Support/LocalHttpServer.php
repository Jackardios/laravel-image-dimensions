<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Support;

use RuntimeException;

/**
 * A PHP built-in web server on a free local port, for tests that need a real
 * cURL transfer (deadlines, early stops, redirects) rather than Http::fake().
 */
final class LocalHttpServer
{
    /** @var resource */
    private $process;

    private function __construct(
        public readonly string $baseUrl,
        $process,
    ) {
        $this->process = $process;
    }

    public static function start(): self
    {
        $port = self::freePort();
        $env = getenv();
        // Stalling endpoints must not block the next request (not supported,
        // and ignored, on Windows).
        $env['PHP_CLI_SERVER_WORKERS'] = '4';

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.DIRECTORY_SEPARATOR.'http-server-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', self::nullDevice(), 'w'], 2 => ['file', self::nullDevice(), 'w']],
            $pipes,
            null,
            $env,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the PHP built-in web server.');
        }

        $server = new self("http://127.0.0.1:{$port}", $process);
        $server->waitUntilListening($port);

        return $server;
    }

    public function url(string $path): string
    {
        return $this->baseUrl.$path;
    }

    public function stop(): void
    {
        // With PHP_CLI_SERVER_WORKERS the server forks; its workers outlive
        // a terminated parent.
        $pid = proc_get_status($this->process)['pid'];
        foreach (self::childProcesses($pid) as $child) {
            exec('kill '.$child);
        }

        proc_terminate($this->process);
        proc_close($this->process);
    }

    /**
     * @return list<int>
     */
    private static function childProcesses(int $pid): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [];
        }

        if (! is_dir('/proc/self')) {
            exec('pgrep -P '.$pid, $output);

            return array_map('intval', $output);
        }

        $children = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $stat) {
            // "pid (comm) state ppid ...": comm may contain spaces.
            $line = (string) @file_get_contents($stat);
            $fields = explode(' ', substr($line, (int) strrpos($line, ')') + 2));
            if ((int) ($fields[1] ?? 0) === $pid) {
                $children[] = (int) basename(dirname($stat));
            }
        }

        return $children;
    }

    private function waitUntilListening(int $port): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(50000);
        }

        $this->stop();

        throw new RuntimeException("The PHP built-in web server did not start on port {$port}.");
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Could not find a free port.');
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }
}
