<?php

namespace App\Support\SysCompare;

/**
 * What every area needs while it builds its tab, plus a place for areas to
 * report facts that are not rows (such as whether API key values differ).
 */
class ComparisonContext
{
    /** @var array<string, true> sha256 of every distinct API key value seen; raw values are never stored */
    private array $apiKeyFingerprints = [];

    /**
     * @param  list<SysEnvironment>  $environments  in the order the user gave them
     */
    public function __construct(
        public readonly array $environments,
        public readonly int $baselineIndex,
        public readonly ParamDefinitions $definitions,
        public readonly ParameterCatalog $catalog,
    ) {
        $this->resolver = new EffectiveParameterResolver($catalog);
    }

    public readonly EffectiveParameterResolver $resolver;

    /** @var array<int, EnvironmentParameters> */
    private array $parameterModels = [];

    /**
     * The profiles and explicitly set parameters of an environment.
     */
    public function parameters(int $environmentIndex): EnvironmentParameters
    {
        return $this->parameterModels[$environmentIndex] ??= EnvironmentParameters::fromSysFile($this->environments[$environmentIndex]->sysFile);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (SysEnvironment $environment): string => $environment->name, $this->environments);
    }

    public function baselineName(): string
    {
        return $this->environments[$this->baselineIndex]->name;
    }

    /**
     * @return list<array<string, string>>
     */
    public function rows(int $environmentIndex, string $table): array
    {
        return $this->environments[$environmentIndex]->sysFile->rows($table);
    }

    /**
     * Records that a secret was seen, keeping only a fingerprint of it.
     */
    public function noteApiKeyValue(string $value): void
    {
        $this->apiKeyFingerprints[hash('sha256', $value)] = true;
    }

    public function apiKeyValuesDiffer(): bool
    {
        return count($this->apiKeyFingerprints) > 1;
    }

    /**
     * Builds one value per environment for a key, using $absent when the environment has none.
     *
     * @param  list<array<string, string>>  $valueMaps  one key => value map per environment
     * @return list<string>
     */
    public static function valuesFor(array $valueMaps, string $key, string $absent = Cells::ABSENT): array
    {
        return array_map(static fn (array $map): string => $map[$key] ?? $absent, $valueMaps);
    }

    /**
     * Distinct keys across all maps, in first-seen order.
     *
     * @param  list<array<string, mixed>>  $maps
     * @return list<string>
     */
    public static function unionKeys(array $maps): array
    {
        $keys = [];

        foreach ($maps as $map) {
            foreach (array_keys($map) as $key) {
                $keys[(string) $key] = true;
            }
        }

        return array_map('strval', array_keys($keys));
    }
}
