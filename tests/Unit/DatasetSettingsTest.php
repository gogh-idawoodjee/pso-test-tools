<?php

use App\Support\DatasetSettings;

it('maps a pso-tools account file onto the typed keys', function () {
    $settings = DatasetSettings::fromAccountFile([
        'display_name' => 'SaskTel',
        'coverage_km' => 50,
        'eod_cutoff_hour' => 22,
        'exclude_resource_types' => ['LONGCYCLE'],
        'resource_type_names' => ['DISTRICTTECH' => 'district tech'],
        'attributes' => ['task_type' => 'Task Type'],
        'region_pivot' => ['red_below' => 1, 'amber_below' => 3],
        'notes' => ['end_of_day' => 'Take exports before 21:30.'],
        'regions' => ['district_label' => 'Territory', 'district_label_plural' => 'Territories'],
        'supervisor_label' => 'Company',
    ]);

    expect($settings->displayName)->toBe('SaskTel')
        ->and($settings->coverageKm)->toBe(50.0)
        ->and($settings->eodCutoffHour)->toBe(22)
        ->and($settings->excludeResourceTypes)->toBe(['LONGCYCLE'])
        ->and($settings->attributeLabels)->toBe(['task_type' => 'Task Type'])
        ->and($settings->regionPivot)->toBe(['red_below' => 1, 'amber_below' => 3])
        ->and($settings->regionLabels)->toBe(['district_label' => 'Territory', 'district_label_plural' => 'Territories'])
        ->and($settings->supervisorLabel)->toBe('Company')
        ->and($settings->timeZone)->toBeNull();
});

it('round trips through toArray and fromArray', function () {
    $settings = DatasetSettings::fromArray(['display_name' => 'Rogers', 'coverage_km' => 15, 'time_zone' => 'America/Toronto']);

    expect(DatasetSettings::fromArray($settings->toArray())->toArray())->toBe($settings->toArray())
        ->and($settings->toArray()['time_zone'])->toBe('America/Toronto');
});

it('rejects unknown keys and bad values', function () {
    expect(fn () => DatasetSettings::fromArray(['colour' => 'blue']))->toThrow(InvalidArgumentException::class, 'colour');
    expect(fn () => DatasetSettings::fromArray(['coverage_km' => 'far']))->toThrow(InvalidArgumentException::class, 'coverage_km');
    expect(fn () => DatasetSettings::fromArray(['time_zone' => 'Mars/Olympus']))->toThrow(InvalidArgumentException::class, 'time_zone');
});

it('has sensible defaults when nothing is set', function () {
    $settings = DatasetSettings::fromArray([]);

    expect($settings->coverageKm)->toBe(50.0)
        ->and($settings->regionPivot)->toBe(['red_below' => 1, 'amber_below' => 3])
        ->and($settings->excludeResourceTypes)->toBe([])
        ->and($settings->displayName)->toBeNull();
});

it('writes the pso-tools account file keys and round trips through fromAccountFile', function () {
    $settings = DatasetSettings::fromArray([
        'display_name' => 'SaskTel',
        'dataset_display_name' => 'North',
        'exclude_resource_types' => ['LONGCYCLE'],
        'attribute_labels' => ['district' => 'District', 'supervisor_email' => 'Supervisor Email'],
        'region_labels' => ['district_label' => 'Territory'],
        'time_zone' => 'America/Regina',
    ]);

    $file = $settings->toAccountFile();

    expect($file['attributes'])->toBe(['district' => 'District', 'supervisor_email' => 'Supervisor Email'])
        ->and($file['dataset_name'])->toBe('North')
        ->and($file['regions'])->toBe(['district_label' => 'Territory'])
        ->and($file)->not->toHaveKeys(['attribute_labels', 'dataset_display_name', 'region_labels', 'eod_cutoff_hour', 'supervisor_label', 'windows_time_zone', 'resource_type_names', 'notes'])
        ->and($file['region_pivot'])->toBe(['red_below' => 1, 'amber_below' => 3])
        ->and(DatasetSettings::fromAccountFile($file)->toArray())->toBe($settings->toArray());
});

it('keeps the region label, blank means none, and writes it to the account file when set', function () {
    expect(DatasetSettings::fromArray([])->regionLabel)->toBeNull()
        ->and(DatasetSettings::fromArray(['region_label' => ''])->regionLabel)->toBeNull();

    $settings = DatasetSettings::fromArray(['region_label' => 'Territory']);

    expect($settings->regionLabel)->toBe('Territory')
        ->and($settings->toArray()['region_label'])->toBe('Territory')
        ->and($settings->toAccountFile()['region_label'])->toBe('Territory')
        ->and(DatasetSettings::fromAccountFile($settings->toAccountFile())->toArray())->toBe($settings->toArray())
        ->and(DatasetSettings::fromArray([])->toAccountFile())->not->toHaveKey('region_label');
});

it('round trips the two booking SLA settings', function () {
    $s = DatasetSettings::fromArray(['appointment_sla_type_id' => 'Appointment', 'default_booking_sla_type_id' => 'BOOKED']);
    expect($s->appointmentSlaTypeId)->toBe('Appointment')
        ->and($s->defaultBookingSlaTypeId)->toBe('BOOKED')
        ->and($s->toArray())->toMatchArray(['appointment_sla_type_id' => 'Appointment', 'default_booking_sla_type_id' => 'BOOKED']);
});
