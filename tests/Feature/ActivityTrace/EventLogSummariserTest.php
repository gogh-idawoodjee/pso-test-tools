<?php

use App\Enums\ExportKind;
use App\Support\Exports\ExportSummariser;
use App\Support\Exports\InvalidExportException;

function eventLogXlsx(array $rows, string $header = 'Created Date'): string
{
    $strings = [];
    $index = static function (string $text) use (&$strings): int {
        $position = array_search($text, $strings, true);
        if ($position === false) {
            $strings[] = $text;
            $position = count($strings) - 1;
        }

        return $position;
    };
    $cell = static function (string $ref, mixed $value) use ($index): string {
        return is_int($value) || is_float($value)
            ? "<c r=\"{$ref}\"><v>{$value}</v></c>"
            : "<c r=\"{$ref}\" t=\"s\"><v>{$index((string) $value)}</v></c>";
    };

    $sheetRows = '<row r="1">'.$cell('A1', 'Event Id').$cell('B1', $header).'</row>';
    foreach ($rows as $number => [$id, $created]) {
        $row = $number + 2;
        $sheetRows .= "<row r=\"{$row}\">".$cell("A{$row}", $id).$cell("B{$row}", $created).'</row>';
    }

    $path = tempnam(sys_get_temp_dir(), 'ev_').'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Events" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
    $sharedItems = implode('', array_map(static fn (string $text): string => '<si><t>'.htmlspecialchars($text).'</t></si>', $strings));
    $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$sharedItems.'</sst>');
    $zip->close();

    return $path;
}

it('summarises an xlsx event log with numeric and text created dates in the given zone', function () {
    // 46205.3541666667 is 2026-07-02 08:30:00 (OADate); the second row is text.
    $path = eventLogXlsx([['E1', 46205.3541666667], ['E2', '2026-10-04 08:30:00']]);

    $summary = app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::EventLog, 'xlsx'), $path, 'America/Regina');

    expect($summary->toArray())->toBe([
        'event_count' => 2,
        'first_event' => '2026-07-02T14:30:00+00:00',
        'last_event' => '2026-10-04T14:30:00+00:00',
    ]);
});

it('summarises a csv event log', function () {
    $path = tempnam(sys_get_temp_dir(), 'ev_').'.csv';
    file_put_contents($path, "Event Id,Created Date\nE1,2026-10-04 09:00:00\nE2,2026-10-04 07:00:00\nE3,\n");

    $summary = app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::EventLog, 'csv'), $path, 'UTC');

    expect($summary->toArray())->toBe([
        'event_count' => 2,
        'first_event' => '2026-10-04T07:00:00+00:00',
        'last_event' => '2026-10-04T09:00:00+00:00',
    ]);
});

it('fails with a plain message when there is no usable Created Date column', function () {
    $path = eventLogXlsx([['E1', '2026-10-04 08:30:00']], header: 'Logged At');

    expect(fn () => app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::EventLog, 'xlsx'), $path, 'UTC'))
        ->toThrow(InvalidExportException::class, 'No usable Created Date column');
});

it('fails when no row has a datable Created Date', function () {
    $path = tempnam(sys_get_temp_dir(), 'ev_').'.csv';
    file_put_contents($path, "Event Id,Created Date\nE1,not a date\n");

    expect(fn () => app(ExportSummariser::class)->summarise(fileOfKind(ExportKind::EventLog, 'csv'), $path, 'UTC'))
        ->toThrow(InvalidExportException::class, 'No usable Created Date column');
});
