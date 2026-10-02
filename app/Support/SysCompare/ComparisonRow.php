<?php

namespace App\Support\SysCompare;

final readonly class ComparisonRow
{
    public const string SAME = 'Same';

    /** The shown values differ only because one side leaves a parameter unset and uses its default. */
    public const string SAME_DEFAULT = 'Same (default)';

    public const string DIFF = 'DIFF';

    public string $status;

    /** @var list<string> what decides sameness: the shown values, or their canonical forms */
    public array $compareValues;

    /**
     * @param  list<string>  $keyValues  the leading identifying columns
     * @param  list<string>  $cellValues  one value per environment, in environment order, as shown
     * @param  array<string, string>  $extra  trailing columns, by header (e.g. Definition)
     * @param  list<string>|null  $compareValues  canonical values used to decide sameness; defaults to the shown values
     */
    public function __construct(
        public array $keyValues,
        public array $cellValues,
        public array $extra = [],
        ?array $compareValues = null,
    ) {
        $this->compareValues = $compareValues ?? $cellValues;
        $this->status = $this->resolveStatus();
    }

    /**
     * Only rows whose effective values differ count as differing.
     */
    public function isDifferent(): bool
    {
        return $this->status === self::DIFF;
    }

    /**
     * Whether the environment at $index differs from the one at $baselineIndex (effective values).
     */
    public function differsFromBaseline(int $index, int $baselineIndex): bool
    {
        return $index !== $baselineIndex && $this->compareValues[$index] !== $this->compareValues[$baselineIndex];
    }

    private function resolveStatus(): string
    {
        foreach ($this->compareValues as $compareValue) {
            if ($compareValue !== $this->compareValues[0]) {
                return self::DIFF;
            }
        }

        foreach ($this->cellValues as $cellValue) {
            if ($cellValue !== $this->cellValues[0]) {
                return self::SAME_DEFAULT;
            }
        }

        return self::SAME;
    }
}
