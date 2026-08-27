<?php

use App\Services\TechnicianAvailabilityService;
use Carbon\Carbon;

it('maps resources to id/name pairs, falling back to id when no name is present', function () {
    $service = new TechnicianAvailabilityService([
        'Resources' => [
            ['id' => 'T1', 'first_name' => 'Ada', 'surname' => 'Lovelace'],
            ['id' => 'T2', 'first_name' => '', 'surname' => ''],
        ],
    ]);

    expect($service->getTechnicians())->toBe([
        ['id' => 'T1', 'name' => 'Ada Lovelace'],
        ['id' => 'T2', 'name' => 'T2'],
    ]);
});

it('filters shifts to the given technician and respects the limit', function () {
    $data = [
        'Shift' => [
            ['id' => 'S1', 'resource_id' => 'T1', 'start_datetime' => '2026-01-05T09:00:00-05:00', 'end_datetime' => '2026-01-05T17:00:00-05:00'],
            ['id' => 'S2', 'resource_id' => 'T2', 'start_datetime' => '2026-01-05T09:00:00-05:00', 'end_datetime' => '2026-01-05T17:00:00-05:00'],
            ['id' => 'S3', 'resource_id' => 'T1', 'start_datetime' => '2026-01-06T09:00:00-05:00', 'end_datetime' => '2026-01-06T17:00:00-05:00'],
        ],
    ];

    $service = new TechnicianAvailabilityService($data, technicianId: 'T1', startDate: '2026-01-01');

    $shifts = $service->getTechnicianShifts(limit: 1);

    expect($shifts)->toHaveCount(1)
        ->and($shifts[0]['id'])->toBe('S1');
});

it('excludes shifts before the given start date', function () {
    $data = [
        'Shift' => [
            ['id' => 'OLD', 'resource_id' => 'T1', 'start_datetime' => '2026-01-01T09:00:00-05:00', 'end_datetime' => '2026-01-01T17:00:00-05:00'],
            ['id' => 'NEW', 'resource_id' => 'T1', 'start_datetime' => '2026-01-10T09:00:00-05:00', 'end_datetime' => '2026-01-10T17:00:00-05:00'],
        ],
    ];

    $service = new TechnicianAvailabilityService($data, technicianId: 'T1', startDate: '2026-01-05');

    $shifts = $service->getTechnicianShifts();

    expect(array_column($shifts, 'id'))->toBe(['NEW']);
});

it('attaches an overlapping direct availability to a shift, with source_id defaulting to null', function () {
    $data = [
        'Shift' => [
            ['id' => 'S1', 'resource_id' => 'T1', 'start_datetime' => '2026-01-05T09:00:00-05:00', 'end_datetime' => '2026-01-05T17:00:00-05:00'],
        ],
        'Availability' => [
            ['id' => 'A1', 'datetime_start' => '2026-01-05T08:00:00-05:00', 'datetime_end' => '2026-01-05T12:00:00-05:00'],
        ],
        'Resource_Region_Availability' => [
            ['resource_id' => 'T1', 'region_id' => 'R1', 'availability_id' => 'A1', 'within_region_multiplier' => 1.0, 'override_priority' => 2],
        ],
        'Region' => [
            ['id' => 'R1', 'description' => 'Region One'],
        ],
    ];

    $service = new TechnicianAvailabilityService($data, technicianId: 'T1', startDate: '2026-01-01');

    $availability = $service->getTechnicianShifts()[0]['region_availability'][0];

    expect($availability)->toMatchArray([
        'id' => 'A1',
        'region_id' => 'R1',
        'region_description' => 'R1 - Region One',
        'region_group_id' => 'R1',
        'region_group_description' => 'R1 - Region One',
        'region_active' => true,
        'full_coverage' => false,
        'source' => 'availability',
        'source_id' => null,
        'override_priority' => 2,
        'within_region_multiplier' => 1.0,
    ])
        ->and(Carbon::parse($availability['start']))->toEqual(Carbon::parse('2026-01-05T09:00:00-05:00'))
        ->and(Carbon::parse($availability['end']))->toEqual(Carbon::parse('2026-01-05T12:00:00-05:00'));
});

it('resolves the top-most parent region description for grouping', function () {
    $data = [
        'Shift' => [
            ['id' => 'S1', 'resource_id' => 'T1', 'start_datetime' => '2026-01-05T09:00:00-05:00', 'end_datetime' => '2026-01-05T17:00:00-05:00'],
        ],
        'Availability' => [
            ['id' => 'A1', 'datetime_start' => '2026-01-05T08:00:00-05:00', 'datetime_end' => '2026-01-05T18:00:00-05:00'],
        ],
        'Resource_Region_Availability' => [
            ['resource_id' => 'T1', 'region_id' => 'CHILD', 'availability_id' => 'A1'],
        ],
        'Region' => [
            ['id' => 'CHILD', 'description' => 'Child Region', 'region_id' => 'PARENT'],
            ['id' => 'PARENT', 'description' => 'Parent Region'],
        ],
    ];

    $service = new TechnicianAvailabilityService($data, technicianId: 'T1', startDate: '2026-01-01');

    $availability = $service->getTechnicianShifts()[0]['region_availability'][0];

    expect($availability['region_description'])->toBe('CHILD - Child Region')
        ->and($availability['region_group_id'])->toBe('PARENT')
        ->and($availability['region_group_description'])->toBe('PARENT - Parent Region')
        ->and($availability['full_coverage'])->toBeTrue();
});

it('disambiguates regions that share the same description by prefixing the region id', function () {
    $data = [
        'Shift' => [
            ['id' => 'S1', 'resource_id' => 'T1', 'start_datetime' => '2026-01-05T09:00:00-05:00', 'end_datetime' => '2026-01-05T17:00:00-05:00'],
        ],
        'Availability' => [
            ['id' => 'A1', 'datetime_start' => '2026-01-05T08:00:00-05:00', 'datetime_end' => '2026-01-05T18:00:00-05:00'],
        ],
        'Resource_Region_Availability' => [
            ['resource_id' => 'T1', 'region_id' => 'R1', 'availability_id' => 'A1'],
            ['resource_id' => 'T1', 'region_id' => 'R2', 'availability_id' => 'A1'],
        ],
        'Region' => [
            ['id' => 'R1', 'description' => 'Zone'],
            ['id' => 'R2', 'description' => 'Zone'],
        ],
    ];

    $service = new TechnicianAvailabilityService($data, technicianId: 'T1', startDate: '2026-01-01');

    $descriptions = collect($service->getTechnicianShifts()[0]['region_availability'])
        ->pluck('region_description')
        ->all();

    expect($descriptions)->toEqualCanonicalizing(['R1 - Zone', 'R2 - Zone']);
});

it('computes shift breaks from earliest_start_offset and duration', function () {
    $data = [
        'Shift' => [
            ['id' => 'S1', 'resource_id' => 'T1', 'start_datetime' => '2026-01-05T09:00:00-05:00', 'end_datetime' => '2026-01-05T17:00:00-05:00'],
        ],
        'Shift_Break' => [
            ['shift_id' => 'S1', 'earliest_start_offset' => 'PT4H', 'duration' => 'PT30M'],
        ],
    ];

    $service = new TechnicianAvailabilityService($data, technicianId: 'T1', startDate: '2026-01-01');

    $break = $service->getTechnicianShifts()[0]['breaks'][0];

    expect(Carbon::parse($break['start']))->toEqual(Carbon::parse('2026-01-05T13:00:00-05:00'))
        ->and(Carbon::parse($break['end']))->toEqual(Carbon::parse('2026-01-05T13:30:00-05:00'));
});

it('expands pattern-based availability into a region_availability entry for matching days', function () {
    $data = [
        'Shift' => [
            ['id' => 'S1', 'resource_id' => 'T1', 'start_datetime' => '2026-01-05T09:00:00-05:00', 'end_datetime' => '2026-01-05T17:00:00-05:00'],
        ],
        'Resource_Region_Availability' => [
            [
                'resource_id' => 'T1',
                'region_id' => 'R1',
                'availability_pattern_id' => 'P1',
                'within_region_multiplier' => 1.0,
            ],
        ],
        'Availability_Pattern' => [
            [
                'id' => 'P1',
                'period_start_datetime' => '2026-01-01T00:00:00-05:00',
                'period_end_datetime' => '2026-01-31T00:00:00-05:00',
                'day_pattern' => 'YYYYYNN', // Monday=2026-01-05
                'open_time' => 'PT8H',
                'close_time' => 'PT16H',
                'time_zone' => 'America/Toronto',
            ],
        ],
    ];

    $service = new TechnicianAvailabilityService($data, technicianId: 'T1', startDate: '2026-01-01');

    $availability = $service->getTechnicianShifts()[0]['region_availability'][0];

    expect($availability['source'])->toBe('pattern')
        ->and($availability['region_id'])->toBe('R1')
        ->and($availability['source_id'])->toBeNull();
});

it('excludes shifts starting in the past when onlyUpcoming is true', function () {
    $data = [
        'Shift' => [
            ['id' => 'PAST', 'resource_id' => 'T1', 'start_datetime' => now()->subDay()->toIso8601String(), 'end_datetime' => now()->subDay()->addHours(8)->toIso8601String()],
            ['id' => 'FUTURE', 'resource_id' => 'T1', 'start_datetime' => now()->addDay()->toIso8601String(), 'end_datetime' => now()->addDay()->addHours(8)->toIso8601String()],
        ],
    ];

    $service = new TechnicianAvailabilityService($data, technicianId: 'T1', startDate: now()->subWeek()->toDateString());

    $shifts = $service->getTechnicianShifts(onlyUpcoming: true);

    expect(array_column($shifts, 'id'))->toBe(['FUTURE']);
});
