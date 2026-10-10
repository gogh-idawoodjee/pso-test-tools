<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Jobs\SummariseExportFileJob;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-test-'.uniqid()]);
});

it('streams the object, writes the summary, marks ready and cleans scratch', function () {
    $file = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'xml', 'status' => ExportFileStatus::Summarising]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), file_get_contents(scheduleXml()));

    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(\App\Support\Exports\ExportSummariser::class));

    $file->refresh();
    expect($file->status)->toBe(ExportFileStatus::Ready)
        ->and($file->summary['activity_count'])->toBe(2)
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
});

it('marks the row failed with the summariser message on a mismatched root', function () {
    $file = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'xml', 'status' => ExportFileStatus::Summarising]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), file_get_contents(sysXml()));

    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(\App\Support\Exports\ExportSummariser::class));

    expect($file->refresh()->status)->toBe(ExportFileStatus::Failed)
        ->and($file->error_message)->toContain('DsSystemData');
});

it('marks the row failed when the object is missing', function () {
    $file = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['status' => ExportFileStatus::Summarising]);

    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(\App\Support\Exports\ExportSummariser::class));

    expect($file->refresh()->status)->toBe(ExportFileStatus::Failed)
        ->and($file->error_message)->toContain('no longer in storage');
});

it('fails a zip whose only member is a SYS export uploaded as a LOAD', function () {
    $file = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'zip', 'status' => ExportFileStatus::Summarising]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), file_get_contents(zipOf(sysXml(), 'load.xml')));

    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(\App\Support\Exports\ExportSummariser::class));

    expect($file->refresh()->status)->toBe(ExportFileStatus::Failed)
        ->and($file->error_message)->toContain('DsSystemData')
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
});

it('reads a zip that was named as xml by what it is, not by its name', function () {
    $file = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'xml', 'status' => ExportFileStatus::Summarising]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), file_get_contents(zipOf(scheduleXml(activities: 3), 'load.xml')));

    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(\App\Support\Exports\ExportSummariser::class));

    expect($file->refresh()->status)->toBe(ExportFileStatus::Ready)
        ->and($file->summary['activity_count'])->toBe(3);
});
