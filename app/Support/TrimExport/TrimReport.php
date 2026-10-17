<?php

namespace App\Support\TrimExport;

/** What one trim did: rows before and kept per table, the tables removed whole, what is left dangling, and notes. */
class TrimReport
{
    /**
     * @param  array<string, array{before: int, kept: int}>  $tables  in file order
     * @param  list<string>  $removedTables
     * @param  list<array{table: string, field: string, count: int, examples: list<string>}>  $dangling
     * @param  list<string>  $notes  plain English, never an id
     */
    public function __construct(
        public array $tables,
        public array $removedTables,
        public array $dangling,
        public array $notes,
        public int $bytesWritten,
    ) {}

    /** @return list<string> */
    public function keptTables(): array
    {
        return array_values(array_diff(array_keys($this->tables), $this->removedTables));
    }

    public function rowsKept(): int
    {
        return array_sum(array_column($this->tables, 'kept'));
    }

    public function rowsRemoved(): int
    {
        return array_sum(array_column($this->tables, 'before')) - $this->rowsKept();
    }

    /** Counts only, never ids or names: this line is stored in ToolRun.summary. */
    public function summaryLine(bool $countOnly): string
    {
        $removedTables = self::plural(count($this->removedTables), 'table');
        $removedRows = self::plural($this->rowsRemoved(), 'row');
        $keptTables = self::plural(count($this->keptTables()), 'table');
        $keptRows = self::plural($this->rowsKept(), 'row');

        return $countOnly
            ? "Would remove {$removedTables} and {$removedRows}, and keep {$keptTables} and {$keptRows}. Nothing was written (Count only)."
            : "Removed {$removedTables} and {$removedRows}. Kept {$keptTables} and {$keptRows}.";
    }

    public static function plural(int $count, string $word): string
    {
        return number_format($count).' '.($count === 1 ? $word : $word.'s');
    }
}
