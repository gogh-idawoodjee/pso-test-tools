<?php

namespace App\Support\SysCompare;

/**
 * One row of the IFS parameter catalog: where a parameter lives, its type, its default
 * and the official description.
 */
final readonly class CatalogEntry
{
    public function __construct(
        public string $application,
        public string $parameterId,
        public string $dataType,
        public string $defaultValue,
        public string $description,
    ) {}
}
