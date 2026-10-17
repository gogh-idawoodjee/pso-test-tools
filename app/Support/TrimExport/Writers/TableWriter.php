<?php

namespace App\Support\TrimExport\Writers;

use App\Support\TrimExport\Segment;

/** Writes the kept segments of a file. Every segment is passed, kept or not, so the writer can repair what sits between kept ones. */
interface TableWriter
{
    public function open(string $path): void;

    /** $body, when given, replaces the segment's body (the Input_Reference override). */
    public function write(Segment $segment, bool $keep, ?string $body = null): void;

    /** @return int bytes written */
    public function close(): int;

    public function abort(): void;
}
