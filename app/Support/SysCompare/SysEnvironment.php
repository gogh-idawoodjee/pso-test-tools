<?php

namespace App\Support\SysCompare;

final readonly class SysEnvironment
{
    public function __construct(
        public string $name,
        public SysFile $sysFile,
    ) {}
}
