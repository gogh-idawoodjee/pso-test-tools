<?php

namespace App\Support\TrimExport\Writers;

use App\Support\TrimExport\Segment;

/** Copies the head, every kept row with the bytes before it, and the tail, verbatim: same declaration, namespace and root. */
class XmlTableWriter implements TableWriter
{
    private OutputFile $output;

    public function __construct()
    {
        $this->output = new OutputFile;
    }

    public function open(string $path): void
    {
        $this->output->open($path);
    }

    public function write(Segment $segment, bool $keep, ?string $body = null): void
    {
        if (! $keep || $segment->type === Segment::TABLE_OPEN || $segment->type === Segment::TABLE_CLOSE) {
            return;
        }

        $this->output->write($segment->prefix.($body ?? $segment->body));
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
