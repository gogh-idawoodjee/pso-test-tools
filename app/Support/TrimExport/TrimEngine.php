<?php

namespace App\Support\TrimExport;

use App\Support\TrimExport\Readers\JsonTableReader;
use App\Support\TrimExport\Readers\XmlTableReader;
use App\Support\TrimExport\Writers\JsonTableWriter;
use App\Support\TrimExport\Writers\XmlTableWriter;
use Closure;
use Throwable;

/**
 * Trims one schedule data file. Tables first: a row of a removed table is skipped and never cascades. Then,
 * when a row filter is set, pass one (KeyCollector) decides the keep sets and pass two skips every row of a
 * kept table that points at a removed activity, resource, location or shift. Everything else is copied byte
 * for byte, and the Input_Reference row takes the datetime override. The writer's tally gives the dangling list.
 */
class TrimEngine
{
    public const string PASS_ONE = 'Reading the keys (pass one)';

    public const string PASS_TWO = 'Writing the trimmed file (pass two)';

    public function __construct(
        private XmlTableReader $xmlReader,
        private JsonTableReader $jsonReader,
        private KeyCollector $keys,
    ) {}

    /** @param Closure(string, int): void|null $progress label and percent */
    public function run(string $path, string $format, TrimPlan $plan, ?string $outputPath, ?Closure $progress = null): TrimReport
    {
        $reader = $format === 'json' ? $this->jsonReader : $this->xmlReader;
        $keep = null;
        $start = 0;

        if ($plan->filtersRows()) {
            if ($progress !== null) {
                $progress(self::PASS_ONE, 0);
            }
            $keep = $this->keys->collect($reader, $path, $plan, $progress === null ? null : static fn (int $percent) => $progress(self::PASS_ONE, intdiv($percent, 2)));
            $start = 50;
        }

        if ($progress !== null) {
            $progress(self::PASS_TWO, $start);
        }

        $writer = $outputPath === null ? null : ($format === 'json' ? new JsonTableWriter : new XmlTableWriter);
        $writer?->open($outputPath);

        $fieldNames = array_values(array_unique([...KeepSets::FIELDS, ...ReferenceTally::FIELDS]));
        $tally = new ReferenceTally;
        $tables = [];
        $dropped = [];
        $overrideApplied = false;
        $size = max(1, (int) @filesize($path));
        $rows = 0;

        try {
            foreach ($reader->segments($path) as $segment) {
                if ($segment->type === Segment::HEAD || $segment->type === Segment::TAIL) {
                    $writer?->write($segment, true);

                    continue;
                }

                $table = $segment->table;
                $dropped[$table] ??= $plan->dropsTable($table);
                $tables[$table] ??= ['before' => 0, 'kept' => 0];

                if ($segment->type !== Segment::ROW) {
                    $writer?->write($segment, ! $dropped[$table]);

                    continue;
                }

                $tables[$table]['before']++;
                $keepRow = false;
                $body = null;

                if (! $dropped[$table]) {
                    $fields = $reader->fieldsOf($segment->body, $fieldNames);
                    $keepRow = $keep === null || $keep->keepsRow($table, $fields);

                    if ($keepRow) {
                        $tables[$table]['kept']++;
                        $tally->record($table, $fields);

                        if ($table === TableDependencies::INPUT_REFERENCE && $plan->inputReferenceDatetime !== null) {
                            $body = $reader->replaceField($segment->body, 'datetime', $plan->inputReferenceDatetime);
                            $overrideApplied = $overrideApplied || $body !== $segment->body || $reader->fieldsOf($segment->body, ['datetime']) !== [];
                        }
                    }
                }

                $writer?->write($segment, $keepRow, $body);

                if ($progress !== null && ++$rows % 5000 === 0) {
                    $progress(self::PASS_TWO, min(100, $start + intdiv($segment->offset * (100 - $start), $size)));
                }
            }

            $bytesWritten = $writer?->close() ?? 0;
        } catch (Throwable $exception) {
            $writer?->abort();

            throw $exception;
        }

        $notes = $keep?->notes ?? [];
        if ($plan->inputReferenceDatetime !== null && ! $overrideApplied) {
            $notes[] = 'The Input_Reference datetime was not changed: the file has no Input_Reference row with a datetime, or it is removed.';
        }

        $present = array_map('strval', array_keys($tables));

        return new TrimReport($tables, $plan->droppedTables($present), $tally->dangling($present), $notes, $bytesWritten);
    }
}
