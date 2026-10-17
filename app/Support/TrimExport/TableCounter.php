<?php

namespace App\Support\TrimExport;

use App\Support\TrimExport\Readers\JsonTableReader;
use App\Support\TrimExport\Readers\XmlTableReader;

/** Rows per table, streamed, for a file whose summary predates table_counts. */
class TableCounter
{
    public function __construct(private XmlTableReader $xmlReader, private JsonTableReader $jsonReader) {}

    /** @return array<string, int> table => rows, in file order */
    public function count(string $path, string $format): array
    {
        $counts = [];
        foreach (($format === 'json' ? $this->jsonReader : $this->xmlReader)->segments($path) as $segment) {
            if ($segment->type === Segment::TABLE_OPEN) {
                $counts[$segment->table] ??= 0;
            } elseif ($segment->type === Segment::ROW) {
                $counts[$segment->table] = ($counts[$segment->table] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
