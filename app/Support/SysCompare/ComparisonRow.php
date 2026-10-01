<?php

namespace App\Support\SysCompare;

final readonly class ComparisonRow
{
    public const string SAME = 'Same';

    public const string DIFF = 'DIFF';

    public string $status;

    /**
     * @param  list<string>  $keyValues  the leading identifying columns
     * @param  list<string>  $cellValues  one value per environment, in environment order
     * @param  array<string, string>  $extra  trailing columns, by header (e.g. Definition)
     */
    public function __construct(
        public array $keyValues,
        public array $cellValues,
        public array $extra = [],
    ) {
        $this->status = $this->resolveStatus();
    }

    public function isDifferent(): bool
    {
        return $this->status === self::DIFF;
    }

    /**
     * Whether the environment at $index differs from the one at $baselineIndex.
     */
    public function differsFromBaseline(int $index, int $baselineIndex): bool
    {
        return $index !== $baselineIndex && $this->cellValues[$index] !== $this->cellValues[$baselineIndex];
    }

    private function resolveStatus(): string
    {
        foreach ($this->cellValues as $cellValue) {
            if ($cellValue !== $this->cellValues[0]) {
                return self::DIFF;
            }
        }

        return self::SAME;
    }
}
