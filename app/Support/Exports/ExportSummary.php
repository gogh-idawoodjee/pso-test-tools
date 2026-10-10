<?php

namespace App\Support\Exports;

/** The few facts kept about an uploaded export (counts, ids and times only). */
class ExportSummary
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }
}
