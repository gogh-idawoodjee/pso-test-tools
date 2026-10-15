<?php

use App\Support\ScheduleDataSummaryExtractor;

function scheduleDataSummaryFixturePath(string $name): string
{
    return storage_path("framework/testing/{$name}");
}

afterEach(function () {
    foreach (glob(storage_path('framework/testing/summary-*')) as $file) {
        @unlink($file);
    }
});

it('extracts dataset id, datetime, and counts from an xml file', function () {
    $path = scheduleDataSummaryFixturePath('summary-test.xml');
    file_put_contents($path, <<<'XML'
        <dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd">
            <Input_Reference>
                <datetime>2026-09-10T12:26:05-04:00</datetime>
                <dataset_id>DCU</dataset_id>
            </Input_Reference>
            <Resources><id>Trailer_130</id></Resources>
            <Resources><id>Trailer_150</id></Resources>
            <Activity><id>ACT-1</id></Activity>
        </dsScheduleData>
        XML);

    $summary = ScheduleDataSummaryExtractor::extract($path, 'xml');

    expect($summary['dataset_id'])->toBe('DCU');
    expect($summary['input_reference_datetime'])->not->toBeNull();
    expect($summary['input_reference_datetime']->toIso8601String())->toBe('2026-09-10T12:26:05-04:00');
    expect($summary['resource_count'])->toBe(2);
    expect($summary['activity_count'])->toBe(1);
});

it('returns nulls and zero counts when the xml has no Input_Reference, Activity, or Resources', function () {
    $path = scheduleDataSummaryFixturePath('summary-empty.xml');
    file_put_contents($path, '<dsScheduleData xmlns="http://360Scheduling.com/Schema/dsScheduleData.xsd"><Region><id>NORTH</id></Region></dsScheduleData>');

    $summary = ScheduleDataSummaryExtractor::extract($path, 'xml');

    expect($summary['dataset_id'])->toBeNull();
    expect($summary['input_reference_datetime'])->toBeNull();
    expect($summary['activity_count'])->toBe(0);
    expect($summary['resource_count'])->toBe(0);
});

it('returns the empty summary for corrupt xml instead of throwing', function () {
    $path = scheduleDataSummaryFixturePath('summary-corrupt.xml');
    file_put_contents($path, '<dsScheduleData><Unclosed>');

    $summary = ScheduleDataSummaryExtractor::extract($path, 'xml');

    expect($summary['dataset_id'])->toBeNull();
    expect($summary['activity_count'])->toBe(0);
});

it('extracts dataset id, datetime, and counts from a json file', function () {
    $path = scheduleDataSummaryFixturePath('summary-test.json');
    file_put_contents($path, json_encode([
        'dsScheduleData' => [
            'Input_Reference' => ['datetime' => '2026-09-10T12:26:05-04:00', 'dataset_id' => 'DCU'],
            'Resources' => [['id' => 'Trailer_130'], ['id' => 'Trailer_150']],
            'Activity' => [['id' => 'ACT-1']],
        ],
    ]));

    $summary = ScheduleDataSummaryExtractor::extract($path, 'json');

    expect($summary['dataset_id'])->toBe('DCU');
    expect($summary['input_reference_datetime']->toIso8601String())->toBe('2026-09-10T12:26:05-04:00');
    expect($summary['resource_count'])->toBe(2);
    expect($summary['activity_count'])->toBe(1);
});

it('handles Input_Reference represented as a single-element json array', function () {
    $path = scheduleDataSummaryFixturePath('summary-listed.json');
    file_put_contents($path, json_encode([
        'dsScheduleData' => [
            'Input_Reference' => [['datetime' => '2026-09-10T12:26:05-04:00', 'dataset_id' => 'DCU']],
        ],
    ]));

    $summary = ScheduleDataSummaryExtractor::extract($path, 'json');

    expect($summary['dataset_id'])->toBe('DCU');
});

it('returns the empty summary for malformed json instead of throwing', function () {
    $path = scheduleDataSummaryFixturePath('summary-corrupt.json');
    file_put_contents($path, '{not valid json');

    $summary = ScheduleDataSummaryExtractor::extract($path, 'json');

    expect($summary['dataset_id'])->toBeNull();
    expect($summary['activity_count'])->toBe(0);
});

it('reads a json file far larger than the old 20 MB parse cap', function () {
    $path = tempnam(sys_get_temp_dir(), 'extractjson');

    $handle = fopen($path, 'wb');
    fwrite($handle, '{"dsScheduleData":{"Input_Reference":{"datetime":"2026-09-10T12:26:05Z","dataset_id":"BIG"},"Activity":[');
    $row = '{"id":"A","description":"'.str_repeat('x', 200).'"},';

    for ($index = 0; $index < 100000; $index++) {
        fwrite($handle, $row);
    }

    fwrite($handle, '{"id":"LAST"}],"Resources":[{"id":"R1"},{"id":"R2"}]}}');
    fclose($handle);

    try {
        expect(filesize($path))->toBeGreaterThan(20 * 1024 * 1024);

        $summary = ScheduleDataSummaryExtractor::extract($path, 'json');

        expect($summary['dataset_id'])->toBe('BIG');
        expect($summary['activity_count'])->toBe(100001);
        expect($summary['resource_count'])->toBe(2);
    } finally {
        @unlink($path);
    }
});

it('reads the 6.15+ ScheduleData wrapper', function () {
    $path = tempnam(sys_get_temp_dir(), 'extractjson');
    file_put_contents($path, json_encode(['ScheduleData' => [
        'Input_Reference' => [['datetime' => '2026-09-10T12:26:05Z', 'dataset_id' => 'NEWFMT']],
        'Activity' => [['id' => 'A1'], ['id' => 'A2'], ['id' => 'A3']],
        'Resources' => [['id' => 'R1']],
    ]]));

    try {
        $summary = ScheduleDataSummaryExtractor::extract($path, 'json');

        expect($summary['dataset_id'])->toBe('NEWFMT');
        expect($summary['activity_count'])->toBe(3);
        expect($summary['resource_count'])->toBe(1);
        expect($summary['input_reference_datetime'])->not->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('counts a legacy single-object entity as one row', function () {
    $path = tempnam(sys_get_temp_dir(), 'extractjson');
    file_put_contents($path, '{"dsScheduleData":{"Resources":{"id":"R1"}}}');

    try {
        expect(ScheduleDataSummaryExtractor::extract($path, 'json')['resource_count'])->toBe(1);
    } finally {
        @unlink($path);
    }
});

it('reads an Input_Reference datetime without an offset as UTC, because PSO exports in UTC', function () {
    $path = scheduleDataSummaryFixturePath('summary-no-offset.xml');
    file_put_contents($path, '<dsScheduleData><Input_Reference><datetime>2026-10-11T02:30:00</datetime><dataset_id>NORTH</dataset_id></Input_Reference></dsScheduleData>');

    $summary = ScheduleDataSummaryExtractor::extract($path, 'xml');

    expect($summary['input_reference_datetime']->toIso8601String())->toBe('2026-10-11T02:30:00+00:00');
});
