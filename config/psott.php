<?php

use Illuminate\Support\Str;

return [

    'pso-services-api' => Str::after(env('PSO_SERVICES_API', 'pso-services.test'), '://'),
    'pso-services-api-version' => env('PSO_SERVICES_API_VERSION'),
    'google_api_key' => env('GOOGLE_MAPS_GEOCODING_API_KEY', ''),
    'defaults' => [
        'timeout' => env('DEFAULT_TIMEOUT', 5),
    ],
    'session' => [
        // Logon goes pso-test-tools -> pso-services -> PSO, so it needs more than the 5s default.
        'timeout' => (int) env('PSO_SESSION_TIMEOUT', 20),
    ],
    'custom_key' => env('SHREDDER_KEY'),

    'reference_values' => [
        // The gateway returns the whole dataset body; pso-services streams and parses it.
        'timeout' => (int) env('REFERENCE_VALUES_TIMEOUT', 600),
    ],
];
