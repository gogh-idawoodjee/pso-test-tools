<?php

namespace App\Support\SysCompare;

/**
 * Resolves a profile parameter to the value an environment really uses:
 * explicit value, else the catalog default, else "(absent)" when the parameter is not in the
 * catalog. Profiles do NOT inherit from each other: a parameter unset in any profile uses its
 * catalog default, never the DEFAULT profile's value. A profile the environment does not have
 * resolves to "(no profile)".
 *
 * Secrets (any parameter id containing "key") are shown as "[API key set]"; their canonical
 * form is a hash so the raw value is never stored.
 */
class EffectiveParameterResolver
{
    public const string NO_PROFILE = '(no profile)';

    public const string DEFAULT_PREFIX = '(default: ';

    public const string MASKED = '[API key set]';

    public function __construct(private readonly ParameterCatalog $catalog) {}

    public function resolve(EnvironmentParameters $environment, string $profile, string $parameterId, string $application): EffectiveValue
    {
        if (! isset($environment->profiles[$profile])) {
            return new EffectiveValue(self::NO_PROFILE, self::NO_PROFILE);
        }

        $secret = stripos($parameterId, 'key') !== false;
        $entry = $this->catalog->entry($parameterId, $application);
        $type = $entry?->dataType ?? '';
        $key = EnvironmentParameters::key($profile, $parameterId, $application);

        if (array_key_exists($key, $environment->explicit)) {
            $value = $environment->explicit[$key];

            return new EffectiveValue($this->show($value, $secret), $this->compareValue($value, $type, $secret));
        }

        if ($entry !== null) {
            $default = $entry->defaultValue;

            return new EffectiveValue(
                self::DEFAULT_PREFIX.($default === '' ? 'blank' : ($secret ? self::MASKED : $default)).')',
                $this->compareValue($default, $type, $secret),
            );
        }

        return new EffectiveValue(Cells::ABSENT, Cells::ABSENT);
    }

    /**
     * The catalog default as shown in the Default column.
     */
    public function defaultLabel(string $parameterId, string $application): string
    {
        $entry = $this->catalog->entry($parameterId, $application);

        return match (true) {
            $entry === null => '',
            $entry->defaultValue === '' => '(blank)',
            stripos($parameterId, 'key') !== false => self::MASKED,
            default => $entry->defaultValue,
        };
    }

    /**
     * The canonical form that decides sameness. For a secret (explicit or default) it is a hash,
     * so equal secrets still compare equal but the value is never kept.
     */
    private function compareValue(?string $value, string $type, bool $secret): string
    {
        if ($secret && $value !== null && $value !== '') {
            return 'secret:'.hash('sha256', $value);
        }

        return ValueNormalizer::canonical($value, $type);
    }

    private function show(?string $value, bool $secret): string
    {
        if ($value === null || $value === '') {
            return Cells::NULL_TEXT;
        }

        return $secret ? self::MASKED : Cells::format($value);
    }
}
