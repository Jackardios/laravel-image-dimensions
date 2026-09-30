<?php

declare(strict_types=1);

namespace Jackardios\ImageDimensions\Support;

/**
 * Reads from a stream that may be non-blocking.
 *
 * @internal
 */
final class StreamReader
{
    private const CHUNK_SIZE = 1048576;

    /** Empty reads in a row, 5 ms apart, after which a silent stream counts as ended. */
    private const MAX_EMPTY_READS = 50;

    /**
     * Up to $maxBytes from a stream (none for zero or less); fewer once it
     * ends or stays silent.
     *
     * The end of a stream is detected by feof(), so an empty read only means
     * "nothing available right now", as with a non-blocking stream. Those are
     * retried for about 250 ms rather than taken as the end.
     *
     * @param  resource  $stream
     */
    public static function read($stream, int $maxBytes): string
    {
        $data = '';
        $emptyReads = 0;

        while (strlen($data) < $maxBytes && ! feof($stream) && $emptyReads < self::MAX_EMPTY_READS) {
            $chunk = @fread($stream, max(1, min(self::CHUNK_SIZE, $maxBytes - strlen($data))));

            if ($chunk === false) {
                break;
            }

            if ($chunk === '') {
                $emptyReads++;
                usleep(5000);

                continue;
            }

            $emptyReads = 0;
            $data .= $chunk;
        }

        return $data;
    }
}
