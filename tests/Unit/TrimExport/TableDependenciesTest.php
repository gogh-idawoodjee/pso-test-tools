<?php

use App\Support\TrimExport\TableDependencies;

it('copies the Remove-PsoTables cleanup list verbatim', function () {
    expect(TableDependencies::CLEANUP_TABLES)->toBe([
        'Plan', 'Plan_Resource', 'Plan_Route', 'Plan_Travel', 'Plan_Break', 'Allocation', 'Allocation_Data',
        'Schedule_Event', 'Schedule_Exception', 'Schedule_Exception_Data', 'Schedule_Exception_Response',
        'Appointment_Request', 'Appointment_Offer', 'Appointment_Offer_Response', 'Appointment_Summary',
        'Explanation_Request', 'Explanation_Request_Change', 'Broadcast', 'Broadcast_Parameter',
        'Additional_Attribute', 'Activity_Custom_URL', 'Resource_Custom_URL', 'URL_String', 'URL_Parameter',
        'Object_Group', 'Source_Data', 'Source_Data_Parameter',
    ])->and(TableDependencies::DEPENDS_ON['Allocation'])->toBe(['Activity', 'Resources', 'Plan'])
        ->and(TableDependencies::DEPENDS_ON['Resource_Region_Availability'])->toBe(['Resource_Region'])
        ->and(TableDependencies::DEPENDS_ON)->toHaveCount(26);
});

it('ticks only the cleanup tables that are in the file', function () {
    expect(TableDependencies::cleanupPresent(['Activity', 'Plan_Travel', 'Input_Reference', 'Plan', 'Broadcast']))
        ->toBe(['Plan', 'Plan_Travel', 'Broadcast']);
});

it('warns when a kept table loses a parent that was in the file', function () {
    $present = ['Activity', 'Activity_Status', 'Activity_SLA', 'Allocation', 'Plan', 'Resources', 'Region', 'Input_Reference'];

    expect(TableDependencies::warnings($present, ['Activity', 'Plan']))->toBe([
        'Activity_SLA is kept but Activity is removed, so its rows will point at nothing.',
        'Activity_Status is kept but Activity is removed, so its rows will point at nothing.',
        'Allocation is kept but Activity and Plan are removed, so its rows will point at nothing.',
    ]);
});

it('says nothing about a parent the file never had, or a child that goes too', function () {
    // Resource_Region depends on Resources and Region; Region is not in the file.
    expect(TableDependencies::warnings(['Resource_Region', 'Resources'], []))->toBe([])
        ->and(TableDependencies::warnings(['Activity', 'Activity_Status'], ['Activity', 'Activity_Status']))->toBe([]);
});
