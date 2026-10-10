<?php

use App\Filament\Pages\Activity\GenerateActivities;

it('sends skills and regions as flat lists of pso ids', function () {
    $page = new GenerateActivities;
    $page->selectedEnvironment = null;
    $page->activity_data = [
        'activity_type_id' => 'INSTALL', 'sla_type_id' => 'Appointment', 'base_value' => 3000, 'duration' => 60, 'priority' => 5,
        'latitude' => 50.4, 'longitude' => -104.6,
        'skills' => ['FIBRE', '', 'COPPER'], 'regions' => ['NORTH'],
        'relative_day' => 0, 'relative_day_end' => 7,
    ];

    $payload = callProtectedMethod($page, 'generateActivitiesPayload');

    expect(data_get($payload, 'data.skills'))->toBe(['FIBRE', 'COPPER'])
        ->and(data_get($payload, 'data.regions'))->toBe(['NORTH']);
});
