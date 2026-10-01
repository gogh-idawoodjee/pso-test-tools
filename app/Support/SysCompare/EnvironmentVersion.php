<?php

namespace App\Support\SysCompare;

use DateTimeImmutable;

final readonly class EnvironmentVersion
{
    public const string LATEST = 'LATEST';

    public const string BEHIND = 'BEHIND';

    public const string UNKNOWN = 'UNKNOWN';

    /**
     * @param  list<VersionRecord>  $history  every System_Version record, oldest first
     */
    public function __construct(
        public string $name,
        public bool $hasData,
        public string $current = '',
        public string $release = '',
        public string $upgradedFrom = '',
        public string $upgradedTo = '',
        public ?DateTimeImmutable $upgradedAt = null,
        public ?int $daysSinceUpgrade = null,
        public string $patchVersion = '',
        public ?DateTimeImmutable $patchedAt = null,
        public ?DateTimeImmutable $createdAt = null,
        public string $status = self::UNKNOWN,
        public array $history = [],
    ) {}

    public function withStatus(string $status): self
    {
        return new self(
            $this->name, $this->hasData, $this->current, $this->release, $this->upgradedFrom, $this->upgradedTo,
            $this->upgradedAt, $this->daysSinceUpgrade, $this->patchVersion, $this->patchedAt, $this->createdAt,
            $status, $this->history,
        );
    }
}
