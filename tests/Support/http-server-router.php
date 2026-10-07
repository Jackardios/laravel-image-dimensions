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

// Stalling routes give up after this long: past the 2.5 s the timeout tests
// allow, so a missing deadline still fails them, yet short enough that on
// Windows, where the server takes one request at a time and does not see the
// client leave, the next test does not wait out its own timeout.
const STALL_SECONDS = 3;

$trickle = static function (string $bytes, float $interval): void {
    $deadline = microtime(true) + STALL_SECONDS;
    foreach (str_split($bytes) as $byte) {
        echo $byte;
        flush();
        if (connection_aborted() || microtime(true) > $deadline) {
            return;
        }
        usleep((int) ($interval * 1000000));
    }
};

$zeros = static function (int $length): void {
    $chunk = str_repeat("\0", 65536);
    for ($sent = 0; $sent < $length && ! connection_aborted(); $sent += 65536) {
        echo substr($chunk, 0, $length - $sent);
        flush();
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
        $zeros($tail);
        break;

        // A HEIF test fixture followed by ?tail bytes. ?pad inserts a `free`
        // box of that size after `ftyp`, so the metadata comes later; ?metapad
        // appends one to `meta`, so the metadata ends later.
    case '/heif':
        $body = (string) file_get_contents(dirname(__DIR__).'/fixtures/'.basename((string) ($query['name'] ?? 'p33x17.heic')));
        $pad = (int) ($query['pad'] ?? 0);
        if ($pad > 0) {
            $ftypSize = unpack('N', $body)[1];
            $body = substr($body, 0, $ftypSize).pack('N', 8 + $pad).'free'.str_repeat("\0", $pad).substr($body, $ftypSize);
        }
        $metaPad = (int) ($query['metapad'] ?? 0);
        if ($metaPad > 0) {
            $ftypSize = unpack('N', $body)[1];
            $metaSize = unpack('N', $body, $ftypSize)[1];
            $body = substr($body, 0, $ftypSize).pack('N', $metaSize + 8 + $metaPad).substr($body, $ftypSize + 4, $metaSize - 4)
                .pack('N', 8 + $metaPad).'free'.str_repeat("\0", $metaPad).substr($body, $ftypSize + $metaSize);
        }
        $tail = (int) ($query['tail'] ?? 0);
        header('Content-Length: '.(strlen($body) + $tail));
        header('Content-Type: image/heic');
        echo $body;
        $zeros($tail);
        break;

        // Starts like a WBMP image, which has no signature.
    case '/wbmp-like':
        header('Content-Length: '.(5 + (int) ($query['tail'] ?? 0)));
        echo "\x00\x00\x81\x00\x40";
        $zeros((int) ($query['tail'] ?? 0));
        break;

        // A file a test wrote to its scratch directory.
    case '/test-file':
        $file = (string) realpath((string) ($query['path'] ?? ''));
        if (! str_starts_with(basename(dirname($file)), 'imgdim_test_')) {
            http_response_code(404);
            break;
        }
        header('Content-Length: '.filesize($file));
        readfile($file);
        break;

    case '/png':
        header('Content-Type: image/png');
        echo $png((int) ($query['w'] ?? 10), (int) ($query['h'] ?? 10));
        break;

        // No response for a while: only a total deadline stops this. (The
        // built-in server sends the headers with the first output.)
    case '/slow-headers':
        $deadline = microtime(true) + STALL_SECONDS;
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

        // A redirect whose own body is an image, which must not be measured,
        // followed by ?tail bytes.
    case '/redirect':
        header('Location: '.($query['to'] ?? '/png?w=33&h=44'), true, 302);
        echo $png(7, 7);
        $zeros((int) ($query['tail'] ?? 0));
        break;

        // An image under the status ?code, without a Location, followed by
        // ?tail bytes. With ?slow, it arrives one byte at a time.
    case '/status':
        http_response_code((int) ($query['code'] ?? 200));
        $body = $png(7, 7);
        $tail = (int) ($query['tail'] ?? 0);
        header('Content-Length: '.(strlen($body) + $tail));
        isset($query['slow']) ? $trickle($body, 0.2) : print $body;
        $zeros($tail);
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
