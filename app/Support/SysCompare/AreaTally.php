<?php

namespace App\Support\SysCompare;

/**
 * How many rows of one area differ from the baseline, per environment.
 */
final readonly class AreaTally
{
    /**
     * @param  array<string, int>  $differencesByEnvironment  environment name => rows that differ from the baseline
     */
    public function __construct(
        public string $area,
        public int $rowsCompared,
        public array $differencesByEnvironment,
        public int $notIdenticalAcrossAll,
    ) {}
}
