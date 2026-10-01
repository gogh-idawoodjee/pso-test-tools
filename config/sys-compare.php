<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PSO Sys File Compare
    |--------------------------------------------------------------------------
    |
    | Sys files hold customer data (user accounts, per-user settings and a live
    | routing API key), so they are kept on a private local disk, never on the
    | shared R2 bucket, and are deleted as soon as a comparison finishes.
    |
    | PHP's upload_max_filesize / post_max_size (and nginx client_max_body_size)
    | must allow at least max_file_kilobytes for uploads to succeed.
    |
    */

    'disk' => env('SYS_COMPARE_DISK', 'sys-compare'),

    'max_files' => (int) env('SYS_COMPARE_MAX_FILES', 8),

    'max_file_kilobytes' => (int) env('SYS_COMPARE_MAX_FILE_KILOBYTES', 25 * 1024),

    // How long generated results stay downloadable.
    'run_ttl_minutes' => (int) env('SYS_COMPARE_RUN_TTL_MINUTES', 60),

    // Uploads that never made it into a run are removed after this long.
    'upload_ttl_minutes' => (int) env('SYS_COMPARE_UPLOAD_TTL_MINUTES', 120),

    // Pending uploads allowed per user (guards against a runaway client).
    'max_pending_uploads' => (int) env('SYS_COMPARE_MAX_PENDING_UPLOADS', 20),

];
