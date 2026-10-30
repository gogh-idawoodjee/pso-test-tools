<?php

namespace App\Support\DatasetBuilder;

/** The LOAD file the generator wrote, its size and the page's summary of it (DatasetSummary::toArray()). */
readonly class GeneratedDataset
{
    /** @param array<string, mixed> $summary */
    public function __construct(
        public string $path,
        public int $bytes,
        public array $summary,
    ) {}
}
