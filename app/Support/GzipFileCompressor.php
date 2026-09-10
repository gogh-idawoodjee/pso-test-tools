<?php

namespace App\Support;

use RuntimeException;

class GzipFileCompressor
{
    private const int CHUNK_SIZE = 1024 * 1024; // 1MB

    /**
     * Gzip-compresses $sourcePath to $destinationPath using streaming zlib
     * deflate, reading and writing in fixed-size chunks so neither the full
     * source nor the full compressed output is ever held in memory at once.
     *
     * Every I/O result is checked: a failed open, read or write throws
     * rather than silently producing a truncated archive that would then
     * be POSTed to PSO as if it were complete.
     *
     * @return int The compressed file's size in bytes.
     */
    public static function compress(string $sourcePath, string $destinationPath): int
    {
        $source = @fopen($sourcePath, 'rb');

        if ($source === false) {
            throw new RuntimeException("The file to compress could not be opened for reading: {$sourcePath}");
        }

        $destination = @fopen($destinationPath, 'wb');

        if ($destination === false) {
            fclose($source);

            throw new RuntimeException("The compressed file could not be opened for writing: {$destinationPath}");
        }

        $context = deflate_init(ZLIB_ENCODING_GZIP);

        try {
            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK_SIZE);

                if ($chunk === false) {
                    throw new RuntimeException("The file to compress could not be read: {$sourcePath}");
                }

                $isFinalChunk = feof($source);

                $written = fwrite($destination, deflate_add($context, $chunk, $isFinalChunk ? ZLIB_FINISH : ZLIB_NO_FLUSH));

                if ($written === false) {
                    throw new RuntimeException("The compressed file could not be written: {$destinationPath}");
                }
            }
        } finally {
            fclose($source);
            fclose($destination);
        }

        clearstatcache(true, $destinationPath);

        return (int) filesize($destinationPath);
    }
}
