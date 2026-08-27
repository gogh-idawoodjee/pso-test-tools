<?php

use App\Enums\ScheduleDataUsageType;
use App\Filament\Resources\EnvironmentResource\Pages\EnvironmentTools;

it('groups rows by usage type and keeps the most recent value', function () {
    $groups = (new EnvironmentTools)->groupUsageRows([
        ['Id' => 1, 'ScheduleDataUsageType' => 0, 'OrganisationId' => 1, 'Value' => 10, 'DatetimeStamp' => '2026-01-01T08:00:00+00:00'],
        ['Id' => 2, 'ScheduleDataUsageType' => 0, 'OrganisationId' => 1, 'Value' => 15, 'DatetimeStamp' => '2026-01-02T08:00:00+00:00'],
        ['Id' => 3, 'ScheduleDataUsageType' => 4, 'OrganisationId' => 1, 'Value' => 3, 'DatetimeStamp' => '2026-01-01T08:00:00+00:00'],
    ]);

    $resourceCount = collect($groups)->firstWhere('type', ScheduleDataUsageType::RESOURCE_COUNT);
    $datasetCount = collect($groups)->firstWhere('type', ScheduleDataUsageType::DATASET_COUNT);

    expect($groups)->toHaveCount(2)
        ->and($resourceCount['latestValue'])->toBe(15)
        ->and($resourceCount['latestDatetime'])->toBe('2026-01-02T08:00:00+00:00')
        ->and($resourceCount['readingCount'])->toBe(2)
        ->and($datasetCount['latestValue'])->toBe(3)
        ->and($datasetCount['readingCount'])->toBe(1);
});

it('sorts groups by usage type value', function () {
    $groups = (new EnvironmentTools)->groupUsageRows([
        ['Id' => 1, 'ScheduleDataUsageType' => 4, 'Value' => 3, 'DatetimeStamp' => '2026-01-01T08:00:00+00:00'],
        ['Id' => 2, 'ScheduleDataUsageType' => 0, 'Value' => 10, 'DatetimeStamp' => '2026-01-01T08:00:00+00:00'],
        ['Id' => 3, 'ScheduleDataUsageType' => 2, 'Value' => 7, 'DatetimeStamp' => '2026-01-01T08:00:00+00:00'],
    ]);

    expect(array_column($groups, 'type'))->toBe([
        ScheduleDataUsageType::RESOURCE_COUNT,
        ScheduleDataUsageType::SCHEDULE_WINDOW_LENGTH,
        ScheduleDataUsageType::DATASET_COUNT,
    ]);
});

it('returns an empty array for no rows', function () {
    expect((new EnvironmentTools)->groupUsageRows([]))->toBe([]);
});
