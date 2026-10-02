<?php

namespace App\Support\SysCompare;

use DateTimeImmutable;

/**
 * Everything the renderers need. Contains no Users data and no secret values.
 */
final readonly class ComparisonResult
{
    /**
     * @param  list<EnvironmentSummary>  $environments
     * @param  list<ComparisonTab>  $tabs
     * @param  list<TableCountRow>  $tableCounts
     * @param  list<AreaTally>  $tally
     * @param  array<string, string>  $definitionTemplate  parameter => hint, for parameters with no specific definition
     */
    public function __construct(
        public array $environments,
        public int $baselineIndex,
        public array $tabs,
        public array $tableCounts,
        public array $tally,
        public VersionReport $versions,
        public bool $apiKeyValuesDiffer,
        public bool $defaultsApplied,
        public array $definitionTemplate,
        public QuickRead $quickRead,
        public DateTimeImmutable $generatedAt,
    ) {}

    /**
     * @return list<string>
     */
    public function environmentNames(): array
    {
        return array_map(static fn (EnvironmentSummary $environment): string => $environment->name, $this->environments);
    }

    public function baselineName(): string
    {
        return $this->environments[$this->baselineIndex]->name;
    }

    public function tab(string $title): ?ComparisonTab
    {
        foreach ($this->tabs as $tab) {
            if ($tab->title === $title) {
                return $tab;
            }
        }

        return null;
    }

    public function totalRowsCompared(): int
    {
        return array_sum(array_map(static fn (AreaTally $tally): int => $tally->rowsCompared, $this->tally));
    }

    public function totalNotIdentical(): int
    {
        return array_sum(array_map(static fn (AreaTally $tally): int => $tally->notIdenticalAcrossAll, $this->tally));
    }

    public function totalDifferencesFor(string $environmentName): int
    {
        return array_sum(array_map(
            static fn (AreaTally $tally): int => $tally->differencesByEnvironment[$environmentName] ?? 0,
            $this->tally,
        ));
    }
}
