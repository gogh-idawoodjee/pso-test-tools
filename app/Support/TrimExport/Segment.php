<?php

namespace App\Support\TrimExport;

/**
 * One piece of a schedule data file as a reader cuts it. Concatenating every segment's prefix and body, in
 * order, gives the file back byte for byte. A row's prefix is what sat between it and the previous row (the
 * whitespace, a JSON comma, an XML comment); a writer that drops the row drops its prefix with it.
 */
class Segment
{
    /** Everything before the first table: declaration, root or wrapper opening. */
    public const string HEAD = 'head';

    /** JSON only: a wrapper member's key up to its value (`"Activity": [`, or the whole `"Odd": null`). */
    public const string TABLE_OPEN = 'table_open';

    public const string ROW = 'row';

    /** JSON only: the whitespace and `]` that close a table's list ('' after a one-row object). */
    public const string TABLE_CLOSE = 'table_close';

    /** Everything after the last row: root or wrapper closing and the rest of the file. */
    public const string TAIL = 'tail';

    public function __construct(
        public string $type,
        public string $table = '',
        public string $prefix = '',
        public string $body = '',
        /** Bytes of the file read once this segment is complete, for progress. */
        public int $offset = 0,
        /** JSON: the table was one row object, not a list (the older dsScheduleData shape). */
        public bool $singleObject = false,
        /** JSON: the member's value was a scalar such as null, so the table has no rows. */
        public bool $scalar = false,
    ) {}
}
