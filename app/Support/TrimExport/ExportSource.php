<?php

namespace App\Support\TrimExport;

use App\Support\Exports\ExportFormat;
use App\Support\Exports\InvalidExportException;

/** Opens a scratch copy of an export for trimming: what its bytes are wins over its name; a zip gives its largest member. */
class ExportSource
{
    public function open(string $localPath): ExportSourceFile
    {
        $format = ExportFormat::sniff($localPath);

        if ($format === 'zip') {
            [$path, $memberFormat, $memberName] = ExportFormat::largestMember($localPath);
            $sniffed = ExportFormat::sniff($path);

            return new ExportSourceFile($path, in_array($sniffed, ['xml', 'json'], true) ? $sniffed : $memberFormat, $memberName);
        }

        if ($format === null) {
            throw new InvalidExportException('This file is not XML, JSON or a zip, so it cannot be trimmed.');
        }

        return new ExportSourceFile($localPath, $format, null);
    }
}
