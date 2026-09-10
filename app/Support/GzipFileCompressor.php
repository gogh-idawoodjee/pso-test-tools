<?php

namespace App\Support;

class GzipFileCompressor
{
    private const int CHUNK_SIZE = 1024 * 1024; // 1MB

    /**
     * Gzip-compresses $sourcePath to $destinationPath using streaming zlib
     * deflate, reading and writing in fixed-size chunks so neither the full
     * source nor the full compressed output is ever held in memory at once.
     *
     * @return int The compressed file's size in bytes.
     */
    public static function compress(string $sourcePath, string $destinationPath): int
    {
        $source = fopen($sourcePath, 'rb');
        $destination = fopen($destinationPath, 'wb');
        $context = deflate_init(ZLIB_ENCODING_GZIP);

        while (! feof($source)) {
            $chunk = fread($source, self::CHUNK_SIZE);
            $isFinalChunk = feof($source);
            fwrite($destination, deflate_add($context, $chunk, $isFinalChunk ? ZLIB_FINISH : ZLIB_NO_FLUSH));
        }

        fclose($source);
        fclose($destination);

        return filesize($destinationPath);
    }
}
