<?php

namespace App\Support\SysCompare;

/**
 * The parameter catalog (pso_parameters_reference.csv): defaults, data types and official
 * descriptions. The export lists only parameters that were set explicitly, so an unset
 * parameter is using its catalog default; without the catalog "unset" cannot be told from
 * "really different".
 *
 * Defaults can change between PSO versions, and the catalog is for one version.
 */
class ParameterCatalog
{
    public const string BUILT_IN_FILE = __DIR__.'/Data/pso_parameters_reference.csv';

    private static ?self $builtIn = null;

    /**
     * @param  array<string, list<CatalogEntry>>  $entries  by lower-cased parameter id
     */
    public function __construct(private readonly array $entries = []) {}

    public static function builtIn(): static
    {
        return static::$builtIn ??= static::fromCsv(self::BUILT_IN_FILE);
    }

    /**
     * An empty catalog: nothing is resolved to a default and the comparison falls back to raw values.
     */
    public static function empty(): static
    {
        return new static;
    }

    public static function fromCsv(string $path): static
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return new static;
        }

        try {
            $header = fgetcsv($handle, escape: '');

            if (! is_array($header)) {
                return new static;
            }

            $columns = [];

            foreach ($header as $position => $name) {
                $columns[strtolower(trim(ltrim((string) $name, "\xEF\xBB\xBF")))] = $position;
            }

            if (! isset($columns['parameter_id'])) {
                return new static;
            }

            $entries = [];

            while (($fields = fgetcsv($handle, escape: '')) !== false) {
                $parameterId = (string) ($fields[$columns['parameter_id']] ?? '');

                if ($parameterId === '') {
                    continue;
                }

                $entries[strtolower($parameterId)][] = new CatalogEntry(
                    application: (string) ($fields[$columns['application'] ?? -1] ?? ''),
                    parameterId: $parameterId,
                    dataType: (string) ($fields[$columns['data_type'] ?? -1] ?? ''),
                    defaultValue: (string) ($fields[$columns['default_value'] ?? -1] ?? ''),
                    description: (string) ($fields[$columns['description'] ?? -1] ?? ''),
                );
            }

            return new static($entries);
        } finally {
            fclose($handle);
        }
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Number of distinct parameter ids.
     */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * The catalog entry for a parameter: matched by id (case-insensitive) and application type;
     * when the id exists under several applications and none matches, the first one is used.
     */
    public function entry(string $parameterId, string $application = ''): ?CatalogEntry
    {
        $candidates = $this->entries[strtolower($parameterId)] ?? null;

        if ($candidates === null) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($candidate->application === $application) {
                return $candidate;
            }
        }

        return $candidates[0];
    }
}
