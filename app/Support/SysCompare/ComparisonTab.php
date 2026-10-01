<?php

namespace App\Support\SysCompare;

/**
 * One comparison area: a matrix with one column per environment.
 */
final class ComparisonTab
{
    /** @var list<ComparisonRow> */
    private array $rows = [];

    /**
     * @param  list<string>  $keyHeaders
     * @param  list<string>  $extraHeaders
     */
    public function __construct(
        public readonly string $title,
        public readonly string $csvFileName,
        public readonly array $keyHeaders,
        public readonly array $extraHeaders = [],
    ) {}

    /**
     * @param  list<string>  $keyValues
     * @param  list<string>  $cellValues
     * @param  array<string, string>  $extra
     */
    public function addRow(array $keyValues, array $cellValues, array $extra = []): void
    {
        $this->rows[] = new ComparisonRow($keyValues, $cellValues, $extra);
    }

    /**
     * @return list<ComparisonRow>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return list<ComparisonRow>
     */
    public function differingRows(): array
    {
        return array_values(array_filter($this->rows, static fn (ComparisonRow $row): bool => $row->isDifferent()));
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }
}
