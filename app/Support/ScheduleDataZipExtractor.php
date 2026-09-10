<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

class ScheduleDataZipExtractor
{
    /**
     * Extracts the single .json or .xml entry from $zipPath into
     * $destinationDirectory, returning the extracted file's full path.
     *
     * Fails fast (before any compress/upload work happens) if the zip
     * can't be opened, or doesn't contain exactly one .json/.xml entry.
     */
    public static function extract(string $zipPath, string $destinationDirectory): string
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The uploaded zip file could not be opened. It may be corrupt.');
        }

        if ($zip->numFiles !== 1) {
            $zip->close();

            throw new RuntimeException('The uploaded zip file must contain exactly one file.');
        }

        $entryName = $zip->getNameIndex(0);
        $extension = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));

        if (! in_array($extension, ['json', 'xml'], true)) {
            $zip->close();

            throw new RuntimeException("The file inside the zip must be .json or .xml, found: {$entryName}");
        }

        $zip->extractTo($destinationDirectory, [$entryName]);
        $zip->close();

        return rtrim($destinationDirectory, '/').'/'.$entryName;
    }
}
