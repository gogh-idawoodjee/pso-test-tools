<?php

use App\Support\Exports\InvalidExportException;
use App\Support\TrimExport\ExportSource;

it('opens a plain export where it is, by what its bytes are', function () {
    $path = trimFixtureFile('{"dsScheduleData": {}}', 'xml');

    $source = app(ExportSource::class)->open($path);

    expect($source->path)->toBe($path)
        ->and($source->format)->toBe('json')
        ->and($source->memberName)->toBeNull()
        ->and($source->zipped())->toBeFalse();
});

it('copies the largest member out of a zip next to it, whatever the zip is called', function () {
    $zip = zipOf(trimFixtureFile('<dsScheduleData><Activity/></dsScheduleData>'), 'File_2026_10_04.xml');
    $misnamed = $zip.'.xml';
    rename($zip, $misnamed);

    $source = app(ExportSource::class)->open($misnamed);

    expect($source->format)->toBe('xml')
        ->and($source->memberName)->toBe('File_2026_10_04.xml')
        ->and($source->zipped())->toBeTrue()
        ->and(dirname($source->path))->toBe(dirname($misnamed))
        ->and(file_get_contents($source->path))->toBe('<dsScheduleData><Activity/></dsScheduleData>');
});

it('refuses a file that is not xml, json or a zip', function () {
    expect(fn () => app(ExportSource::class)->open(trimFixtureFile('hello', 'txt')))
        ->toThrow(InvalidExportException::class, 'cannot be trimmed');
});
