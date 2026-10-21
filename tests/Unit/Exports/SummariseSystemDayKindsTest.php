<?php

use App\Enums\ExportKind;
use App\Filament\Tables\ExportFilesTable;
use App\Models\ExportFile;
use App\Support\Exports\ExportSummariser;
use App\Support\Exports\InvalidExportException;

function systemDayScratch(string $name, string $contents): string
{
    $directory = sys_get_temp_dir().'/system-day-'.uniqid();
    mkdir($directory);
    $path = $directory.'/'.$name;
    file_put_contents($path, $contents);

    return $path;
}

function systemDayZip(string $memberName, string $contents): string
{
    $path = sys_get_temp_dir().'/system-day-'.uniqid().'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString($memberName, $contents);
    $zip->close();

    return $path;
}

function systemDayEventsJson(): string
{
    return json_encode([
        ['id' => 1, 'loggedAt' => '\d2026-10-20T18:24:01.123Z', 'messageId' => '2217', 'externalTaskId' => 'DS_A'],
        ['id' => 2, 'loggedAt' => '2026-10-20T19:00:00Z', 'messageId' => '2879', 'externalTaskId' => ''],
        ['id' => 3, 'loggedAt' => '2026-10-20T23:59:59Z', 'messageId' => '2217', 'externalTaskId' => 'DS_A'],
    ]);
}

function systemDayHistoryCsv(): string
{
    $header = 'ID,Dataset,Request ID,Input Internal ID,Plan ID,Request Time,Response Time,Success,Input Time,Output Time,Requested Slots,Offered Slots,Time Taken (seconds),Output Time Taken (seconds),Input Time Taken (seconds)';

    return implode("\n", [
        $header,
        '1,DS_A,r1,i1,p1,2026-10-20 08:00:00,2026-10-20 08:00:02,true,,,3,3,2,1,1',
        '2,DS_B,r2,i2,p2,2026-10-20 09:30:00,2026-10-20 09:30:02,TRUE,,,3,3,2,1,1',
        '3,DS_A,r3,i3,p3,2026-10-20 10:00:00,2026-10-20 10:00:02,false,,,3,0,2,1,1',
        '4,DS_A,r4,i4,p4,2026-10-20 11:00:00,2026-10-20 11:00:02,true,,,3,3,2,1,1',
    ])."\n";
}

function summariseSystemDay(ExportKind $kind, string $path, string $contentType): array
{
    $file = new ExportFile(['kind' => $kind, 'content_type' => $contentType]);

    return app(ExportSummariser::class)->summarise($file, $path, 'Europe/London')->toArray();
}

it('summarises PSO system events and strips the leading backslash-d from times', function () {
    $summary = summariseSystemDay(ExportKind::PsoEvents, systemDayScratch('events.json', systemDayEventsJson()), 'json');

    expect($summary['row_count'])->toBe(3)
        ->and($summary['first_at'])->toBe('2026-10-20T18:24:01+00:00')
        ->and($summary['last_at'])->toBe('2026-10-20T23:59:59+00:00')
        ->and($summary['by_message_id'])->toBe(['2217' => 2, '2879' => 1])
        ->and($summary['datasets'])->toBe(['DS_A']);
});

it('summarises PSO system events inside a zip the same way', function () {
    $summary = summariseSystemDay(ExportKind::PsoEvents, systemDayZip('events.json', systemDayEventsJson()), 'zip');

    expect($summary['row_count'])->toBe(3)
        ->and($summary['first_at'])->toBe('2026-10-20T18:24:01+00:00')
        ->and($summary['by_message_id'])->toBe(['2217' => 2, '2879' => 1]);
});

it('rejects a PSO events file that is not a JSON array', function () {
    summariseSystemDay(ExportKind::PsoEvents, systemDayScratch('events.json', '"nope"'), 'json');
})->throws(InvalidExportException::class);

it('summarises appointment history, reading Request Time in the given zone and keeping UTC', function () {
    $summary = summariseSystemDay(ExportKind::AppointmentHistory, systemDayScratch('history.csv', systemDayHistoryCsv()), 'csv');

    expect($summary['row_count'])->toBe(4)
        ->and($summary['failed_count'])->toBe(1)
        ->and($summary['first_at'])->toBe('2026-10-20T07:00:00+00:00')
        ->and($summary['last_at'])->toBe('2026-10-20T10:00:00+00:00')
        ->and($summary['datasets'])->toBe(['DS_A', 'DS_B']);
});

it('summarises appointment history inside a zip', function () {
    $summary = summariseSystemDay(ExportKind::AppointmentHistory, systemDayZip('history.csv', systemDayHistoryCsv()), 'zip');

    expect($summary['row_count'])->toBe(4)->and($summary['failed_count'])->toBe(1);
});

it('rejects appointment history with no Success column', function () {
    summariseSystemDay(ExportKind::AppointmentHistory, systemDayScratch('history.csv', "ID,Request Time\n1,2026-10-20 08:00:00\n"), 'csv');
})->throws(InvalidExportException::class);

it('words the Contents column for the system day kinds', function (ExportKind $kind, array $summary, ?string $expected) {
    config(['app.timezone' => 'UTC']);
    $file = new ExportFile(['kind' => $kind, 'summary' => $summary]);
    $method = new ReflectionMethod(ExportFilesTable::class, 'contentsOf');

    expect($method->invoke(null, $file))->toBe($expected);
})->with([
    'events' => [ExportKind::PsoEvents, ['row_count' => 1234, 'first_at' => '2026-10-20T18:24:01+00:00', 'last_at' => '2026-10-20T23:59:00+00:00'], '1,234 events, 20 Oct 18:24 to 20 Oct 23:59'],
    'history' => [ExportKind::AppointmentHistory, ['row_count' => 412, 'failed_count' => 3], '412 requests, 3 failed'],
    'empty history' => [ExportKind::AppointmentHistory, [], null],
]);
