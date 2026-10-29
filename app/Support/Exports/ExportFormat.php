<?php

namespace App\Support\Exports;

use Illuminate\Support\Str;
use ZipArchive;

/** What an export is by its first bytes, and the readable member of a zip: shared by the summariser and Trim export. */
class ExportFormat
{
    /** zip, json or xml by the first bytes of the file; null when it is none of them. Zips are often misnamed, so the bytes win. */
    public static function sniff(string $path): ?string
    {
        $head = (string) file_get_contents($path, false, null, 0, 16);
        $head = ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head);

        return match (true) {
            str_starts_with($head, "PK\x03\x04") => 'zip',
            str_starts_with($head, '{') => 'json',
            str_starts_with($head, '<') => 'xml',
            default => null,
        };
    }

    public static function isReadableMember(string $name, ?string $only = null): bool
    {
        if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || str_contains(basename($name), '._')) {
            return false;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $only !== null ? $extension === $only : in_array($extension, ['xml', 'json'], true);
    }

    /**
     * The largest readable member of a zip (by uncompressed size), with only `$only` files when given, after the size cap.
     *
     * @return array{0: string, 1: int} the member's name and its index
     */
    public static function largestMemberName(string $zipPath, ?string $only = null): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new InvalidExportException('The zip could not be opened. It may be damaged or incomplete.');
        }

        try {
            $bestIndex = null;
            $bestSize = -1;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                $stat = $zip->statIndex($index);
                if ($stat !== false && self::isReadableMember($name, $only) && $stat['size'] > $bestSize) {
                    $bestSize = $stat['size'];
                    $bestIndex = $index;
                }
            }
            $bestName = $bestIndex === null ? null : (string) $zip->getNameIndex($bestIndex);
        } finally {
            $zip->close();
        }

        if ($bestName === null) {
            throw new InvalidExportException($only === null ? 'The zip holds no .xml or .json file.' : "The zip holds no .{$only} file.");
        }

        $capMegabytes = (float) config('exports.max_member_megabytes', 600);
        if ($bestSize > $capMegabytes * 1024 * 1024) {
            throw new InvalidExportException('The file inside the zip is too large to read here ('.round($bestSize / 1024 / 1024).' MB uncompressed; the limit is '.round($capMegabytes).' MB).');
        }

        return [$bestName, $bestIndex];
    }

    /**
     * Copies the largest .xml or .json member of a zip next to it.
     *
     * @return array{0: string, 1: string, 2: string} the copy's path, its format (by extension), the member's name
     */
    public static function largestMember(string $zipPath): array
    {
        [$memberName, $bestIndex] = self::largestMemberName($zipPath);
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new InvalidExportException('The zip could not be opened. It may be damaged or incomplete.');
        }

        try {
            $format = strtolower(pathinfo($memberName, PATHINFO_EXTENSION));
            $target = dirname($zipPath).'/member-'.Str::uuid().'.'.$format;
            $source = $zip->getStreamIndex($bestIndex);
            $destination = fopen($target, 'wb');
            try {
                stream_copy_to_stream($source, $destination);
            } finally {
                fclose($destination);
                fclose($source);
            }

            return [$target, $format, $memberName];
        } finally {
            $zip->close();
        }
    }
}
