<?php

namespace App\Support\SysCompare\Storage;

final readonly class StoredUpload
{
    public function __construct(
        public string $id,
        public string $originalName,
        public int $sizeBytes,
    ) {}
}
