<?php

return [
    // R2 disk (config/filesystems.php) that holds raw exports.
    'disk' => env('EXPORTS_DISK', 'r2'),
    // Raw exports are deleted this long after upload. Results in the database are kept.
    'ttl_hours' => (int) env('EXPORTS_TTL_HOURS', 12),
    'max_file_megabytes' => (int) env('EXPORTS_MAX_FILE_MEGABYTES', 400),
    // How long a presigned upload URL stays valid.
    'slot_minutes' => (int) env('EXPORTS_SLOT_MINUTES', 15),
    // Scratch folders older than this are swept by exports:purge (a job died mid-way).
    'scratch_sweep_hours' => (int) env('EXPORTS_SCRATCH_SWEEP_HOURS', 2),
    'scratch_path' => storage_path('app/private/exports'),
];
