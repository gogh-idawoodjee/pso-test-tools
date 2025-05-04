<?php

use Illuminate\Support\Str;

return [

    'pso-services-api' => $clean = Str::after(env('PSO_SERVICES_API', 'pso-services.test'), '://'),
    'pso-services-api-version' => env('PSO_SERVICES_API_VERSION', null),
    'google_api_key' => env('GOOGLE_MAPS_GEOCODING_API_KEY', ''),
    'defaults' => [
        'timeout' => env('DEFAULT_TIMEOUT', 5),
    ]

];
