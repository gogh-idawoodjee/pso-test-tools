<?php

namespace App\Support\SysCompare;

use DateTimeImmutable;

/**
 * One System_Version row. All timestamps are UTC as stored.
 */
final readonly class VersionRecord
{
    public function __construct(
        public string $version,
        public string $type,
        public string $user,
        public ?DateTimeImmutable $stamp,
    ) {}
}
