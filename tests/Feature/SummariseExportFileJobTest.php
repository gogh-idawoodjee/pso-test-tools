<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Jobs\SummariseExportFileJob;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Support\Exports\ExportStorage;
use App\Support\Exports\ExportSummariser;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-test-'.uniqid()]);
});

it('streams the object, writes the summary, marks ready and cleans scratch', function () {
    $file = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'xml', 'status' => ExportFileStatus::Summarising]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), file_get_contents(scheduleXml()));

    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(ExportSummariser::class));

    $file->refresh();
    expect($file->status)->toBe(ExportFileStatus::Ready)
        ->and($file->summary['activity_count'])->toBe(2)
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
});

it('marks the row failed with the summariser message on a mismatched root', function () {
    $file = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'xml', 'status' => ExportFileStatus::Summarising]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), file_get_contents(sysXml()));

    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(ExportSummariser::class));

    expect($file->refresh()->status)->toBe(ExportFileStatus::Failed)
        ->and($file->error_message)->toContain('DsSystemData');
});

it('marks the row failed when the object is missing', function () {
    $file = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['status' => ExportFileStatus::Summarising]);

    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(ExportSummariser::class));

    expect($file->refresh()->status)->toBe(ExportFileStatus::Failed)
        ->and($file->error_message)->toContain('no longer in storage');
});

it('fails a zip whose only member is a SYS export uploaded as a LOAD', function () {
    $file = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'zip', 'status' => ExportFileStatus::Summarising]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), file_get_contents(zipOf(sysXml(), 'load.xml')));

    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(ExportSummariser::class));

    expect($file->refresh()->status)->toBe(ExportFileStatus::Failed)
        ->and($file->error_message)->toContain('DsSystemData')
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
});

it('reads a zip that was named as xml by what it is, not by its name', function () {
    $file = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'xml', 'status' => ExportFileStatus::Summarising]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), file_get_contents(zipOf(scheduleXml(activities: 3), 'load.xml')));

    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(ExportSummariser::class));

    expect($file->refresh()->status)->toBe(ExportFileStatus::Ready)
        ->and($file->summary['activity_count'])->toBe(3);
});

it('allows an audits zip of a full day to be read before the worker gives up', function () {
    expect((new SummariseExportFileJob('any'))->timeout)->toBe(900);
});

it('writes how far it has read onto the file while an audits zip is being summarised', function () {
    $dir = sys_get_temp_dir().'/audits-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/m1.xml", '<Audit><Plan><output_datetime>2026-10-10T08:00:00</output_datetime></Plan></Audit>');
    file_put_contents("$dir/m2.xml", '<Audit><Plan><output_datetime>2026-10-10T09:00:00</output_datetime></Plan></Audit>');
    $zipPath = tempnam(sys_get_temp_dir(), 'exp_').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile("$dir/m1.xml", 'm1.xml');
    $zip->addFile("$dir/m2.xml", 'm2.xml');
    $zip->close();
    $file = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['kind' => ExportKind::Audits, 'content_type' => 'zip', 'status' => ExportFileStatus::Summarising]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), file_get_contents($zipPath));

    $notes = [];
    ExportFile::updated(function (ExportFile $updated) use (&$notes): void {
        $notes[] = [$updated->status, $updated->progressNote()];
    });

    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(ExportSummariser::class));

    expect($notes[0])->toBe([ExportFileStatus::Summarising, 'Read 1 of 2 messages'])
        ->and($file->refresh()->status)->toBe(ExportFileStatus::Ready)
        ->and($file->progressNote())->toBeNull()
        ->and($file->summary['message_count'])->toBe(2);
});
