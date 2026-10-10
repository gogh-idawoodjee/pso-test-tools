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
