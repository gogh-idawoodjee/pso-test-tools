<?php

namespace App\Support\TrimExport;

/** The schedule data file to trim: the scratch copy itself, or the member copied out of a zip. */
class ExportSourceFile
{
    public function __construct(
        public string $path,
        /** xml or json */
        public string $format,
        /** The member's name inside the zip; null for a plain file. */
        public ?string $memberName,
    ) {}

    public function zipped(): bool
    {
        return $this->memberName !== null;
    }
}
