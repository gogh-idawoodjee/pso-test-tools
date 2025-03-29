<?php
return [

    'pso-services-api' => env('PSO_SERVICES_API', 'pso-services.test'),
    'google_api_key' => env('GOOGLE_MAPS_GEOCODING_API_KEY', ''),
    'defaults' => [
        'timeout' => env('DEFAULT_TIMEOUT', 5),
    ]

];
