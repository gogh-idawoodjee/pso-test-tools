<?php

namespace App\Support\SysCompare;

final readonly class VersionReport
{
    public const string BANNER_MISMATCH = 'mismatch';

    public const string BANNER_MATCH = 'match';

    public const string BANNER_UNKNOWN = 'unknown';

    /**
     * @param  list<EnvironmentVersion>  $environments  in the order the environments were given
     * @param  list<string>  $missingNames  environments with no System_Version data
     */
    public function __construct(
        public array $environments,
        public string $highestVersion,
        public bool $comparable,
        public bool $mismatch,
        public string $kindText,
        public int $knownCount,
        public array $missingNames,
    ) {}

    /**
     * A mismatch is red, a confirmed match is green. With fewer than two environments
     * that have version data there is nothing to confirm, so it is never green.
     */
    public function bannerState(): string
    {
        if ($this->mismatch) {
            return self::BANNER_MISMATCH;
        }

        return $this->comparable ? self::BANNER_MATCH : self::BANNER_UNKNOWN;
    }

    /**
     * @return list<EnvironmentVersion>
     */
    public function behind(): array
    {
        return array_values(array_filter(
            $this->environments,
            static fn (EnvironmentVersion $environment): bool => $environment->status === EnvironmentVersion::BEHIND,
        ));
    }
}
