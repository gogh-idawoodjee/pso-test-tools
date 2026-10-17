<?php

use App\Support\TrimExport\TableChoices;

it('groups the tables in the spec order, by name within a group, with their row counts', function () {
    $options = TableChoices::options([
        'Resources' => 85, 'Plan_Travel' => 4812, 'Activity' => 7620, 'Input_Reference' => 1, 'Plan' => 1,
        'Appointment_Request' => 3, 'Location_Region' => 2566, 'Custom_Thing' => 1, 'Shift' => 2,
    ]);

    expect($options)->toBe([
        'Plan' => 'Plan output: Plan, 1 row',
        'Plan_Travel' => 'Plan output: Plan_Travel, 4,812 rows',
        'Appointment_Request' => 'Appointments and events: Appointment_Request, 3 rows',
        'Activity' => 'Activities: Activity, 7,620 rows',
        'Resources' => 'Resources and shifts: Resources, 85 rows',
        'Shift' => 'Resources and shifts: Shift, 2 rows',
        'Location_Region' => 'Locations and regions: Location_Region, 2,566 rows',
        'Custom_Thing' => 'Other: Custom_Thing, 1 row',
        'Input_Reference' => 'Other: Input_Reference, 1 row (always kept unless allowed below)',
    ]);
});

it('sums the rows on each side of the selection for the footer', function () {
    expect(TableChoices::footer(['Plan' => 1, 'Plan_Travel' => 4812, 'Activity' => 7620, 'Input_Reference' => 1], ['Plan', 'Plan_Travel']))
        ->toBe('Removing 2 tables, 4,813 rows. Keeping 2 tables, 7,621 rows.')
        ->and(TableChoices::footer([], []))->toBe('The tables are read from the file when you leave the Inputs step.');
});
