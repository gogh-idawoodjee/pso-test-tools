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
    'appointment' => [
        // Appointment request, check, accept and decline: pso-services waits on PSO for the offers.
        'timeout' => (int) env('APPOINTMENT_TIMEOUT', 60),
    ],
    'routes' => [
        // One chunk of up to 200 trips to pso-services /routes/compute; cache misses call Google one by one with a 150 ms gap.
        'timeout' => (int) env('PSO_SERVICES_ROUTES_TIMEOUT', 300),
        // Sent as X-PSO-Routes-Token when set; pso-services checks it only when its own token is set.
        'token' => env('PSO_ROUTES_TOKEN'),
    ],
    'gateway_upload' => [
        // Defaults for the webhook Broadcast injected into an upload; these
        // mirror Send-PsoScheduleData.ps1 (edit here to change them, as the
        // script's param block is edited there).
        'broadcast' => [
            'id' => 'WEBHOOK_COMPLETE',
            'plan_type' => 'COMPLETE',
            'minimum_plan_quality' => 85,
            'maximum_wait' => 'PT5M',
            'allocation_type' => 1,
            'required_tables' => [
                'Allocation',
                'Plan',
                'Plan_Deletion',
                'Appointment_Offer',
                'Appointment_Summary',
                'Suggested_Dispatch',
                'Schedule_Exception',
                'Schedule_Exception_Data',
            ],
        ],
    ],
    'custom_key' => env('SHREDDER_KEY'),

    'reference_values' => [
        // The gateway returns the whole dataset body; pso-services streams and parses it.
        'timeout' => (int) env('REFERENCE_VALUES_TIMEOUT', 600),
    ],
];
