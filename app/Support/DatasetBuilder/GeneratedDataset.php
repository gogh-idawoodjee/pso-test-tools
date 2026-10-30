<?php

namespace App\Support\DatasetBuilder;

/** The LOAD file the generator wrote, its size, the modelling (.arp) file when the spec asks for one, and the page's summary (DatasetSummary::toArray()). */
readonly class GeneratedDataset
{
    /** @param array<string, mixed> $summary */
    public function __construct(
        public string $path,
        public int $bytes,
        public array $summary,
        public ?string $modellingPath = null,
    ) {}
}
