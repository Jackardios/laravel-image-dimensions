<?php

declare(strict_types=1);

/*
 * Router for the PHP built-in web server used by LocalHttpServer. Every
 * endpoint that stalls gives up by itself after a few seconds, so an aborted
 * client never keeps the server busy for long.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);

$png = static function (int $width, int $height): string {
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image, null, 0);

    return (string) ob_get_clean();
};

$trickle = static function (string $bytes, float $interval): void {
    $deadline = microtime(true) + 5;
    foreach (str_split($bytes) as $byte) {
        echo $byte;
        flush();
        if (connection_aborted() || microtime(true) > $deadline) {
            return;
        }
        usleep((int) ($interval * 1000000));
    }
};

switch ($path) {
    // A PNG followed by a long tail: only the header should be downloaded.
    case '/png-with-tail':
        $body = $png(640, 480);
        $tail = (int) ($query['tail'] ?? 50 * 1024 * 1024);
        if (! isset($query['chunked'])) {
            header('Content-Length: '.(strlen($body) + $tail));
        }
        header('Content-Type: image/png');
        echo $body;
        $zeros = str_repeat("\0", 65536);
        for ($sent = 0; $sent < $tail && ! connection_aborted(); $sent += 65536) {
            echo $zeros;
            flush();
        }
        break;

    case '/png':
        header('Content-Type: image/png');
        echo $png((int) ($query['w'] ?? 10), (int) ($query['h'] ?? 10));
        break;

        // No response for a while: only a total deadline stops this. (The
        // built-in server sends the headers with the first output.)
    case '/slow-headers':
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline && ! connection_aborted()) {
            usleep(100000);
        }
        echo $png(1, 1);
        break;

        // The body arrives one byte at a time.
    case '/slow-body':
        header('Content-Type: image/png');
        header('Content-Length: 1000');
        $trickle(str_repeat("\x01", 1000), 0.2);
        break;

        // A redirect whose own body is an image, which must not be measured.
    case '/redirect':
        header('Location: '.($query['to'] ?? '/png?w=33&h=44'), true, 302);
        echo $png(7, 7);
        break;

    case '/empty':
        break;

    case '/svg':
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            .'<!--'.str_repeat('x', (int) ($query['padding'] ?? 0)).'--></svg>';
        header('Content-Type: image/svg+xml');
        header('Content-Length: '.strlen($svg));
        echo $svg;
        break;

        // Bytes that are no image, without a Content-Length.
    case '/stream':
        $chunk = str_repeat('X', 65536);
        for ($sent = 0; $sent < (int) ($query['size'] ?? 0) && ! connection_aborted(); $sent += 65536) {
            echo $chunk;
            flush();
        }
        break;

        // Ignores Accept-Encoding: identity and compresses anyway.
    case '/gzip':
        header('Content-Encoding: gzip');
        header('X-Accept-Encoding: '.($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
        echo gzencode(str_repeat('A', (int) ($query['size'] ?? 1000000)));
        break;

    default:
        http_response_code(404);
}
