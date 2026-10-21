<?php

return [
    // Python interpreter used to run the pso-tools scripts.
    'python' => env('TOOLS_PYTHON', 'python3'),
    // A checkout of the private pso-tools repo (git clone, kept current with git pull). Never copied into this repo.
    'pso_tools_path' => env('TOOLS_PSO_TOOLS_PATH'),
    'load_audit' => [
        // Under RunToolJob's 900 s so the job reports the timeout in plain words.
        'timeout_seconds' => (int) env('TOOLS_LOAD_AUDIT_TIMEOUT_SECONDS', 840),
        // Event ids kept in the System day counts but left out of its lanes and findings; the wizard prefills this.
        'noise_event_ids' => env('TOOLS_LOAD_AUDIT_NOISE_EVENT_IDS', '3041,1767,2959,1964,1965,24578'),
    ],
    'region_availability' => [
        // The viewer reads one LOAD and does no checks; SaskTel's takes well under a minute.
        'timeout_seconds' => (int) env('TOOLS_REGION_AVAILABILITY_TIMEOUT_SECONDS', 600),
    ],
    'activity_trace' => [
        // Reads one audit zip and writes four files; under RunToolJob's 900 s so the timeout is reported in plain words.
        'timeout_seconds' => (int) env('TOOLS_ACTIVITY_TRACE_TIMEOUT_SECONDS', 840),
    ],
    'plan_travels' => [
        // One script run (build, or merge-google); the Google step between them has its own timeout per chunk (psott.routes.timeout).
        'timeout_seconds' => (int) env('TOOLS_PLAN_TRAVELS_TIMEOUT_SECONDS', 600),
        // Seconds from the start of a run after which no more chunks go to pso-services. RunToolJob allows 900 s, so
        // the default 720 leaves time for the merge. A chunk is only started while a full margin (the routes timeout,
        // at most 270 s) still fits before this deadline. 0 means stop at once: no Google calls are made.
        'google_deadline_seconds' => (int) env('TOOLS_PLAN_TRAVELS_GOOGLE_DEADLINE_SECONDS', 720),
    ],
    'travel_tuning' => [
        // One script run over the picked plan travels CSVs and polygon files; the 2026-10-13 SaskTel pair takes seconds.
        'timeout_seconds' => (int) env('TOOLS_TRAVEL_TUNING_TIMEOUT_SECONDS', 600),
    ],
];
