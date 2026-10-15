<?php

return [
    // Python interpreter used to run the pso-tools scripts.
    'python' => env('TOOLS_PYTHON', 'python3'),
    // A checkout of the private pso-tools repo (git clone, kept current with git pull). Never copied into this repo.
    'pso_tools_path' => env('TOOLS_PSO_TOOLS_PATH'),
    'load_audit' => [
        // Under RunToolJob's 900 s so the job reports the timeout in plain words.
        'timeout_seconds' => (int) env('TOOLS_LOAD_AUDIT_TIMEOUT_SECONDS', 840),
    ],
    'region_availability' => [
        // The viewer reads one LOAD and does no checks; SaskTel's takes well under a minute.
        'timeout_seconds' => (int) env('TOOLS_REGION_AVAILABILITY_TIMEOUT_SECONDS', 600),
    ],
    'activity_trace' => [
        // Reads one audit zip and writes four files; under RunToolJob's 900 s so the timeout is reported in plain words.
        'timeout_seconds' => (int) env('TOOLS_ACTIVITY_TRACE_TIMEOUT_SECONDS', 840),
    ],
];
