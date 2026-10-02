<?php

namespace App\Support\SysCompare;

/**
 * The profiles an environment has and the parameters it sets explicitly.
 */
final readonly class EnvironmentParameters
{
    /**
     * @param  array<string, true>  $profiles  profile ids that exist in the environment
     * @param  array<string, string|null>  $explicit  profile|parameter|application => value as stored
     */
    public function __construct(
        public array $profiles,
        public array $explicit,
    ) {}

    public static function key(string $profile, string $parameterId, string $application): string
    {
        return $profile.Cells::KEY_SEPARATOR.$parameterId.Cells::KEY_SEPARATOR.$application;
    }

    public static function fromSysFile(SysFile $sysFile): self
    {
        $profiles = [];

        foreach ($sysFile->rows('Profile') as $row) {
            $profiles[$row['id'] ?? ''] = true;
        }

        $explicit = [];

        foreach ($sysFile->rows('Profile_Parameter') as $row) {
            $key = self::key($row['profile_id'] ?? '', $row['parameter_id'] ?? '', $row['parameter_application_type_id'] ?? '');
            $explicit[$key] = $row['parameter_value'] ?? null;
        }

        return new self($profiles, $explicit);
    }
}
