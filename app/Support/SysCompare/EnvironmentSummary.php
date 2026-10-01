<?php

namespace App\Support\SysCompare;

/**
 * What a result remembers about an environment: its name and the file it came from.
 * Deliberately not the parsed file, so the result never carries raw values or secrets.
 */
final readonly class EnvironmentSummary
{
    public function __construct(
        public string $name,
        public string $fileName,
        public int $sizeBytes,
    ) {}
}
