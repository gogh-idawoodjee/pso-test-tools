<?php

namespace App\Support\SysCompare;

/**
 * One parsed DsSystemData export: the rows of the tables the comparison needs,
 * plus a row count for every table. Users and per-user tables are only ever
 * counted, never retained.
 */
final readonly class SysFile
{
    /**
     * @param  array<string, list<array<string, string>>>  $tables  rows of the compared tables, by table name
     * @param  array<string, int>  $tableCounts  row count of every table in the file
     */
    public function __construct(
        public string $fileName,
        public int $sizeBytes,
        public array $tables,
        public array $tableCounts,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function rows(string $table): array
    {
        return $this->tables[$table] ?? [];
    }

    public function hasTable(string $table): bool
    {
        return isset($this->tableCounts[$table]);
    }
}
