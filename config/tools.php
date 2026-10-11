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
];
