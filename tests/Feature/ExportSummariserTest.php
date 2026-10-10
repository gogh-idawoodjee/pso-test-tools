<?php

use App\Enums\ExportKind;
use App\Models\ExportFile;
use App\Support\Exports\ExportSummariser;
use App\Support\Exports\InvalidExportException;

it('summarises a LOAD xml: dataset, input reference, counts and local export date', function () {
    $summary = app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Load, 'xml'), scheduleXml(datetime: '2026-10-11T02:30:00Z'), 'America/Regina');

    expect($summary->toArray())->toMatchArray([
        'root' => 'dsScheduleData',
        'dataset_id' => 'NORTH',
        'activity_count' => 2,
        'resource_count' => 1,
        'export_date' => '2026-10-10',
    ]);
});

it('summarises a plan inside a zip by its largest xml member', function () {
    $summary = app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Plan, 'zip'), zipOf(scheduleXml(activities: 5), 'whatever.xml'), 'UTC');

    expect($summary->toArray()['activity_count'])->toBe(5);
});

it('rejects a SYS export uploaded as a LOAD, naming both roots', function () {
    expect(fn () => app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Load, 'xml'), sysXml(), 'UTC'))
        ->toThrow(InvalidExportException::class, 'DsSystemData');
});

it('summarises a SYS export without touching users or credentials', function () {
    $summary = app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Sys, 'xml'), sysXml(), 'UTC');

    expect($summary->toArray())->toMatchArray(['root' => 'DsSystemData', 'pso_version' => '6.14.0.0', 'parameter_count' => 2, 'exception_type_count' => 1])
        ->and(json_encode($summary->toArray()))->not->toContain('secret')->not->toContain('admin');
});

it('summarises an audits zip by message count and time span, keeping no contact fields', function () {
    $dir = sys_get_temp_dir().'/audits-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/m1.xml", '<Audit><Plan><output_datetime>2026-10-10T08:00:00</output_datetime></Plan><Appointment_Request><id>AR1</id><customer_phone>555</customer_phone></Appointment_Request></Audit>');
    file_put_contents("$dir/m2.xml", '<Audit><Input_Reference><datetime>2026-10-10T09:00:00</datetime></Input_Reference><Appointment_Offer><id>1</id></Appointment_Offer></Audit>');
    $zipPath = tempnam(sys_get_temp_dir(), 'exp_').'.zip';
    $zip = new ZipArchive; $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile("$dir/m1.xml", 'm1.xml'); $zip->addFile("$dir/m2.xml", 'm2.xml'); $zip->close();

    $summary = app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Audits, 'zip'), $zipPath, 'UTC');

    expect($summary->toArray())->toMatchArray(['message_count' => 2, 'appointment_request_count' => 1, 'first_message_at' => '2026-10-10T08:00:00+00:00', 'last_message_at' => '2026-10-10T09:00:00+00:00'])
        ->and(json_encode($summary->toArray()))->not->toContain('555');
});

it('rejects a zip with no xml or json member', function () {
    $txt = tempnam(sys_get_temp_dir(), 'exp_'); file_put_contents($txt, 'hello');

    expect(fn () => app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::Load, 'zip'), zipOf($txt, 'notes.txt'), 'UTC'))
        ->toThrow(InvalidExportException::class);
});
