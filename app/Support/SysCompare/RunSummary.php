<?php

namespace App\Support\SysCompare;

/**
 * What the results screen shows natively: the version banner, the version table and the tally.
 * Plain arrays, so it can be stored as JSON next to a run's files. Contains no secrets and no user data.
 */
final readonly class RunSummary
{
    /**
     * @param  list<array<string, string|int|null>>  $versions
     * @param  list<array{area: string, compared: int, differences: array<string, int>, notIdentical: int}>  $tally
     * @param  list<string>  $environments
     * @param  array<string, int>  $totalDifferences  environment name => rows differing from the baseline
     * @param  list<string>  $notes  warnings to surface (e.g. API key values differ)
     */
    public function __construct(
        public string $bannerState,
        public string $bannerTitle,
        public string $bannerDetail,
        public array $versions,
        public array $tally,
        public array $environments,
        public string $baseline,
        public int $totalCompared,
        public int $totalNotIdentical,
        public array $totalDifferences,
        public bool $templateAvailable,
        public array $notes,
        public string $generatedAt,
    ) {}

    public static function fromResult(ComparisonResult $result): self
    {
        $versions = $result->versions;

        [$title, $detail] = match ($versions->bannerState()) {
            VersionReport::BANNER_MISMATCH => [
                'NOT ALL ENVIRONMENTS ARE ON THE SAME PSO VERSION',
                'Highest version: '.$versions->highestVersion.'. Behind: '
                .implode('; ', array_map(static fn (EnvironmentVersion $version): string => $version->name.' is on '.$version->current, $versions->behind()))
                .'. '.$versions->kindText,
            ],
            VersionReport::BANNER_MATCH => [
                'All '.$versions->knownCount.' environments are on the same PSO version: '.$versions->highestVersion,
                '',
            ],
            default => [
                'PSO version could not be compared',
                'System_Version data is missing in '.($versions->missingNames === [] ? 'one or more environments' : implode(', ', $versions->missingNames)).'.',
            ],
        };

        $notes = [];

        if ($result->apiKeyValuesDiffer) {
            $notes[] = 'Routing API key values differ between environments (the values are masked).';
        }

        if ($versions->bannerState() === VersionReport::BANNER_MISMATCH && $versions->missingNames !== []) {
            $notes[] = 'No System_Version data in: '.implode(', ', $versions->missingNames).' (version unknown).';
        }

        $names = $result->environmentNames();

        return new self(
            bannerState: $versions->bannerState(),
            bannerTitle: $title,
            bannerDetail: $detail,
            versions: array_map(static fn (EnvironmentVersion $version): array => [
                'environment' => $version->name,
                'current' => $version->current,
                'status' => $version->status,
                'lastUpgrade' => $version->upgradedAt?->format('Y-m-d H:i'),
                'upgradedFrom' => $version->upgradedFrom,
                'upgradedTo' => $version->upgradedTo,
                'lastPatch' => $version->patchedAt !== null ? $version->patchVersion.' on '.$version->patchedAt->format('Y-m-d H:i') : null,
                'daysSinceUpgrade' => $version->daysSinceUpgrade,
            ], $versions->environments),
            tally: array_map(static fn (AreaTally $tally): array => [
                'area' => $tally->area,
                'compared' => $tally->rowsCompared,
                'differences' => $tally->differencesByEnvironment,
                'notIdentical' => $tally->notIdenticalAcrossAll,
            ], $result->tally),
            environments: $names,
            baseline: $result->baselineName(),
            totalCompared: $result->totalRowsCompared(),
            totalNotIdentical: $result->totalNotIdentical(),
            totalDifferences: array_combine($names, array_map(static fn (string $name): int => $result->totalDifferencesFor($name), $names)),
            templateAvailable: $result->definitionTemplate !== [],
            notes: $notes,
            generatedAt: $result->generatedAt->format('Y-m-d H:i').' UTC',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
