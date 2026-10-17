<?php

namespace App\Support\TrimExport\Writers;

use App\Support\TrimExport\Segment;

/**
 * Writes the kept tables and rows in the input's own structure and bytes. The first kept table (and the first
 * kept row of a table) takes the separator of the first one in the input, which has no comma, so dropping the
 * first of anything never leaves a leading comma. A table that was one row object stays an object; when that
 * row goes it becomes [] (the rule of the #26 fix).
 */
class JsonTableWriter implements TableWriter
{
    private OutputFile $output;

    private ?string $firstTablePrefix = null;

    private int $tablesWritten = 0;

    private bool $tableKept = false;

    private bool $singleObject = false;

    private ?string $firstRowPrefix = null;

    private int $rowsWritten = 0;

    public function __construct()
    {
        $this->output = new OutputFile;
    }

    public function open(string $path): void
    {
        $this->output->open($path);
        $this->firstTablePrefix = null;
        $this->tablesWritten = 0;
        $this->tableKept = false;
    }

    public function write(Segment $segment, bool $keep, ?string $body = null): void
    {
        $body ??= $segment->body;

        if ($segment->type === Segment::HEAD || $segment->type === Segment::TAIL) {
            $this->output->write($segment->prefix.$body);

            return;
        }

        if ($segment->type === Segment::TABLE_OPEN) {
            $this->firstTablePrefix ??= $segment->prefix;
            $this->tableKept = $keep;
            $this->singleObject = $segment->singleObject;
            $this->firstRowPrefix = null;
            $this->rowsWritten = 0;

            if ($keep) {
                $this->output->write(($this->tablesWritten === 0 ? $this->firstTablePrefix : $segment->prefix).$body);
                $this->tablesWritten++;
            }

            return;
        }

        if ($segment->type === Segment::ROW) {
            $this->firstRowPrefix ??= $segment->prefix;

            if ($this->tableKept && $keep) {
                $this->output->write(($this->rowsWritten === 0 ? $this->firstRowPrefix : $segment->prefix).$body);
                $this->rowsWritten++;
            }

            return;
        }

        // TABLE_CLOSE
        if ($this->tableKept) {
            $this->output->write($this->singleObject && $this->rowsWritten === 0 ? '[]' : $segment->prefix.$body);
        }
    }

    public function close(): int
    {
        return $this->output->close();
    }

    public function abort(): void
    {
        $this->output->abort();
    }
}
