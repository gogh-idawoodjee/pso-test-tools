<?php

use App\Support\TrimExport\KeepSets;
use App\Support\TrimExport\KeyCollector;
use App\Support\TrimExport\Readers\JsonTableReader;
use App\Support\TrimExport\Readers\XmlTableReader;
use App\Support\TrimExport\TrimPlan;

/** @param array<string, mixed> $options */
function collectKeys(array $options, string $format = 'xml', ?array $tables = null, string $wrapper = 'dsScheduleData'): KeepSets
{
    $tables ??= trimDataset();
    $path = $format === 'xml' ? trimXml($tables) : trimJson($tables, wrapper: $wrapper);
    $reader = $format === 'xml' ? app(XmlTableReader::class) : app(JsonTableReader::class);

    return app(KeyCollector::class)->collect($reader, $path, TrimPlan::fromOptions($options, 'UTC'));
}

it('keeps resources, shifts, activities, locations and availability for a region and a window', function (string $format) {
    $keep = collectKeys(['region_ids' => ['V1'], 'window_start' => '2026-10-05', 'window_end' => '2026-10-09'], $format);
    $locations = $keep->ids('locations');
    sort($locations);

    expect($keep->ids('resources'))->toBe(['R1'])
        ->and($keep->ids('shifts'))->toBe(['S1'])
        ->and($keep->ids('activities'))->toBe(['A1', 'A3'])
        ->and($locations)->toBe(['L1', 'L3', 'LS1', 'LT'])
        ->and($keep->ids('availability'))->toBe(['AV1', 'AV3'])
        ->and($keep->notes)->toBe([]);
})->with(['xml', 'json']);

it('keeps shifts and activities outside any window when none is set', function () {
    $keep = collectKeys(['region_ids' => ['V1']]);

    expect($keep->ids('shifts'))->toBe(['S1', 'S3'])
        ->and($keep->ids('activities'))->toBe(['A1', 'A3', 'A4']);
});

it('narrows resources to the chosen ones, within the chosen regions', function (string $format) {
    $alone = collectKeys(['resource_ids' => ['R2']], $format);
    $outside = collectKeys(['region_ids' => ['V1'], 'resource_ids' => ['R2']], $format);

    expect($alone->ids('resources'))->toBe(['R2'])
        ->and($alone->ids('shifts'))->toBe(['S2'])
        ->and($alone->ids('activities'))->toBe(['A1', 'A2', 'A3', 'A4'])
        ->and($outside->ids('resources'))->toBe([])
        ->and($outside->notes)->toContain('No resources match the row filters, so the file keeps none.');
})->with(['xml', 'json']);

it('filters activities by type and by id, and says so when nothing matches', function (string $format) {
    expect(collectKeys(['activity_type_ids' => ['T2']], $format)->ids('activities'))->toBe(['A3'])
        ->and(collectKeys(['activity_ids' => ['A2', 'A4']], $format)->ids('activities'))->toBe(['A2', 'A4'])
        ->and(collectKeys(['activity_type_ids' => ['T1'], 'activity_ids' => ['A3']], $format)->notes)
        ->toBe(['No activities match the row filters, so the file keeps none.']);
})->with(['xml', 'json']);

it('combines the region, type and window filters', function (string $format) {
    $keep = collectKeys(['region_ids' => ['V1'], 'activity_type_ids' => ['T1'], 'window_start' => '2026-10-05', 'window_end' => '2026-10-09'], $format);

    expect($keep->ids('activities'))->toBe(['A1'])
        ->and($keep->ids('resources'))->toBe(['R1']);
})->with(['xml', 'json']);

it('keeps rows by their own id, by the cascade, and availability never by date', function () {
    $keep = collectKeys(['region_ids' => ['V1'], 'window_start' => '2026-10-05', 'window_end' => '2026-10-09']);

    expect($keep->keepsRow('Activity', ['id' => 'A1']))->toBeTrue()
        ->and($keep->keepsRow('Activity', ['id' => 'A2']))->toBeFalse()
        ->and($keep->keepsRow('Availability', ['id' => 'AV3', 'resource_id' => 'R1']))->toBeTrue()
        ->and($keep->keepsRow('Availability', ['id' => 'AV2', 'resource_id' => 'R2']))->toBeFalse()
        ->and($keep->keepsRow('Availability', ['id' => 'AV1', 'resource_id' => 'R2']))->toBeTrue()
        ->and($keep->keepsRow('Availability', ['id' => 'AVX', 'activity_id' => 'A1', 'resource_id' => 'R2']))->toBeTrue()
        ->and($keep->keepsRow('Shift_Break', ['shift_id' => 'S2']))->toBeFalse()
        ->and($keep->keepsRow('Activity_Group', ['activity_id1' => 'A1', 'activity_id2' => 'A2']))->toBeFalse()
        ->and($keep->keepsRow('Activity_Status', ['activity_id' => 'A1', 'resource_id' => 'R2']))->toBeTrue()
        ->and($keep->keepsRow('Allocation', ['activity_id' => 'A1', 'resource_id' => 'R2']))->toBeFalse();
});

it('matches number ids in a JSON export to the string ids the filters carry', function () {
    $tables = [
        'Location' => [['id' => 'L1', 'region_id' => 'V1']],
        'Activity' => [['id' => 14420, 'location_id' => 'L1', 'activity_type_id' => 'T1'], ['id' => 14421, 'location_id' => 'L1', 'activity_type_id' => 'T1']],
    ];

    expect(collectKeys(['activity_ids' => ['14420']], 'json', $tables, 'ScheduleData')->ids('activities'))->toBe(['14420'])
        ->and(collectKeys(['activity_ids' => ['14420']], 'xml', $tables)->ids('activities'))->toBe(['14420']);
});

it('counts no availability from a table the run removes', function () {
    $keep = collectKeys(['region_ids' => ['V1'], 'table_mode' => 'remove', 'tables' => ['Resource_Region_Availability']]);

    expect($keep->ids('availability'))->toBe(['AV3']);
});

it('reports progress as a percent of the file', function () {
    $percents = [];
    $rows = [];
    for ($index = 0; $index < 12000; $index++) {
        $rows[] = ['id' => "A{$index}", 'location_id' => 'L1', 'activity_type_id' => 'T1'];
    }
    $path = trimXml(['Activity' => $rows]);

    app(KeyCollector::class)->collect(app(XmlTableReader::class), $path, TrimPlan::fromOptions(['activity_type_ids' => ['T1']], 'UTC'),
        static function (int $percent) use (&$percents): void {
            $percents[] = $percent;
        });

    expect($percents)->not->toBe([])
        ->and(max($percents))->toBeLessThanOrEqual(100)
        ->and($percents)->toBe(collect($percents)->sort()->values()->all());
});
