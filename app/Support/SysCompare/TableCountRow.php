<?php

namespace App\Support\SysCompare;

final readonly class TableCountRow
{
    /**
     * @param  list<int>  $counts  one row count per environment, in environment order
     */
    public function __construct(
        public string $table,
        public array $counts,
        public string $scope,
    ) {}
}
