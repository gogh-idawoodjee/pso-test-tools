<?php

namespace App\Tools\TravelTuning;

use App\Enums\ExportKind;
use App\Models\ExportFile;
use App\Support\Exports\ExportFormat;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Str;
use RuntimeException;
use XMLReader;

/**
 * The polygons of a SYS export, for Travel tuning: how many Polygon rows sit directly under DsSystemData (from the
 * summary, or one streamed pass whose count is then kept on the summary), and a plain XML copy in a scratch folder
 * for the script. A SYS export holds live credentials: it is only ever copied into a scratch folder, and whoever
 * asked for the copy deletes it.
 */
class SysPolygons
{
    public function __construct(private ExportStorage $storage) {}

    public function count(ExportFile $file): int
    {
        $stored = $file->summary['polygon_count'] ?? null;
        if (is_int($stored)) {
            return $stored;
        }

        $scratch = $this->storage->scratchDirectoryFor((string) Str::uuid());
        try {
            $count = self::countIn($this->localXml($file, $scratch, 'sys'));
        } finally {
            $this->storage->removeScratch($scratch);
        }
        $file->forceFill(['summary' => [...($file->summary ?? []), 'polygon_count' => $count]])->save();

        return $count;
    }

    /** Copies the export into $directory as <name>.xml (the largest member when it is zipped) and returns the path. */
    public function localXml(ExportFile $file, string $directory, string $name): string
    {
        if ($file->kind !== ExportKind::Sys) {
            throw new RuntimeException('Only a SYS export can hold polygons.');
        }

        $copy = $this->storage->readToScratch($file, $directory);
        $format = ExportFormat::sniff($copy) ?? $file->content_type;
        if ($format === 'zip') {
            try {
                [$member, $format] = ExportFormat::largestMember($copy);
            } finally {
                @unlink($copy);
            }
            $copy = $member;
        }
        if ($format !== 'xml') {
            @unlink($copy);

            throw new RuntimeException('This SYS export is not XML, so its polygons cannot be read.');
        }

        $target = rtrim($directory, '/').'/'.$name.'.xml';
        if (! @rename($copy, $target)) {
            @unlink($copy);

            throw new RuntimeException('The SYS export could not be prepared for the report.');
        }

        return $target;
    }

    /** Polygon rows directly under the root element, whatever the namespace (as the summariser counts tables). */
    public static function countIn(string $xmlPath): int
    {
        $reader = new XMLReader;
        if (! @$reader->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('The SYS export could not be read as XML.');
        }

        $count = 0;
        try {
            $moved = @$reader->read();
            while ($moved) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === 1) {
                    if ($reader->localName === 'Polygon') {
                        $count++;
                    }
                    // next() lands on the following sibling, which must then be looked at, not read past.
                    $moved = @$reader->next();

                    continue;
                }
                $moved = @$reader->read();
            }
        } finally {
            $reader->close();
        }

        return $count;
    }
}
