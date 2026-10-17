<?php

namespace App\Support\TrimExport\Readers;

use App\Support\TrimExport\Segment;
use Generator;

/** Cuts a dsScheduleData file into segments (streamed) and reads or edits single rows. */
interface TableReader
{
    /**
     * @return Generator<int, Segment>
     *
     * @throws \RuntimeException when the file is not schedule data or ends early
     */
    public function segments(string $path): Generator;

    /**
     * @param  list<string>  $names
     * @return array<string, string> the named fields of one row that have a non-empty value, as trimmed strings
     */
    public function fieldsOf(string $body, array $names): array;

    /** The row with one field's value replaced; unchanged when the row has no such field. */
    public function replaceField(string $body, string $field, string $value): string;
}
