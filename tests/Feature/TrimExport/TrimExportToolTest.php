<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Filament\Resources\DatasetResource\RelationManagers\ToolRunsRelationManager as DatasetRuns;
use App\Jobs\RunToolJob;
use App\Jobs\SummariseExportFileJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use App\Support\Exports\ExportSummariser;
use App\Support\Tools\StartToolRun;
use App\Support\Tools\UnusableInputException;
use App\Support\TrimExport\TableDependencies;
use App\Tools\ToolRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    Queue::fake();
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-trim-'.uniqid()]);
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->actingAs($this->user);
});

/** An export of $kind on $scope holding the bytes of $path. */
function storedTrimInput(Model $scope, ExportKind $kind, string $path, string $name, string $contentType): ExportFile
{
    $file = ExportFile::factory()->for($scope, 'scope')->create(['kind' => $kind, 'original_filename' => $name, 'content_type' => $contentType]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), file_get_contents($path));

    return $file;
}

/** @param array<string, mixed> $options */
function runTrimJob(Model $scope, ExportFile $input, array $options, User $user): ToolRun
{
    $run = ToolRun::factory()->for($scope, 'scope')->create([
        'tool' => ToolId::TrimExport, 'status' => ToolRunStatus::Queued, 'result' => null, 'run_date' => null,
        'started_by_user_id' => $user->id,
        'inputs' => ['files' => [$input->kind->value => $input->id], 'options' => $options],
    ]);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    return $run->refresh();
}

/** The bytes of a stored export, in a local file. */
function storedTrimOutput(ExportFile $file): string
{
    $local = tempnam(sys_get_temp_dir(), 'trimmed_');
    file_put_contents($local, Storage::disk('r2')->get(app(ExportStorage::class)->objectKey($file)));

    return $local;
}

it('trims a dataset export into a new export beside it, named .trimmed, and queues its summary', function () {
    $input = storedTrimInput($this->dataset, ExportKind::Load, trimXml(trimDataset()), 'north-load.xml', 'xml');

    $run = runTrimJob($this->dataset, $input, ['table_mode' => 'remove', 'tables' => TableDependencies::CLEANUP_TABLES], $this->user);

    $trimmed = ExportFile::query()->whereKeyNot($input->id)->sole();
    $local = storedTrimOutput($trimmed);

    expect($run->status)->toBe(ToolRunStatus::Succeeded)
        ->and($trimmed->scope_type)->toBe($this->dataset->getMorphClass())
        ->and((string) $trimmed->scope_id)->toBe((string) $this->dataset->getKey())
        ->and($trimmed->kind)->toBe(ExportKind::Load)
        ->and($trimmed->original_filename)->toBe('north-load.trimmed.xml')
        ->and($trimmed->content_type)->toBe('xml')
        ->and($trimmed->uploaded_by_user_id)->toBe($this->user->id)
        ->and($trimmed->status)->toBe(ExportFileStatus::Summarising)
        ->and($trimmed->file_size_bytes)->toBe(filesize($local))
        ->and($trimmed->expires_at->greaterThan(now()->addHours(11)))->toBeTrue()
        ->and(array_keys(trimmedRows($local, 'xml')))->not->toContain('Plan', 'Plan_Travel', 'Allocation')
        ->and($run->result['export_file_id'])->toBe($trimmed->id)
        ->and($run->result['removed_tables'])->toBe(['Plan', 'Plan_Travel', 'Allocation'])
        ->and($run->summary)->toMatchArray(['line' => 'Removed 3 tables and 5 rows. Kept 18 tables and 45 rows.', 'tables_removed' => 3, 'rows_kept' => 45, 'rows_removed' => 5, 'count_only' => false])
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
    Queue::assertPushed(SummariseExportFileJob::class, static fn (SummariseExportFileJob $job): bool => $job->exportFileId === $trimmed->id);
});

it('keeps customer ids and file names out of the summary and warnings, and in the result', function () {
    $input = storedTrimInput($this->dataset, ExportKind::Load, trimXml(trimDataset()), 'north-load.xml', 'xml');

    $run = runTrimJob($this->dataset, $input, ['region_ids' => ['V1'], 'window_start' => '2026-10-05', 'window_end' => '2026-10-09'], $this->user);
    $plain = json_encode([$run->summary, $run->warnings]);

    expect($run->status)->toBe(ToolRunStatus::Succeeded)
        ->and($plain)->not->toContain('R2')->not->toContain('north-load')->not->toContain('V1')->not->toContain('A1')
        ->and($run->warnings)->toBe(['Some kept rows point at rows that are not in the file. The list below names them.'])
        ->and($run->result['dangling'])->toBe([['table' => 'Activity_Status', 'field' => 'resource_id', 'count' => 1, 'examples' => ['R2']]]);
});

it('files the output of an unfiled run under the user', function () {
    $input = storedTrimInput($this->user, ExportKind::Load, trimXml(trimDataset()), 'load.xml', 'xml');

    $run = runTrimJob($this->user, $input, ['tables' => ['Plan']], $this->user);
    $trimmed = ExportFile::query()->findOrFail($run->result['export_file_id']);

    expect($trimmed->scope_type)->toBe($this->user->getMorphClass())
        ->and((string) $trimmed->scope_id)->toBe((string) $this->user->id)
        ->and($trimmed->original_filename)->toBe('load.trimmed.xml');
});

it('writes nothing on Count only', function () {
    $input = storedTrimInput($this->dataset, ExportKind::Load, trimXml(trimDataset()), 'load.xml', 'xml');

    $run = runTrimJob($this->dataset, $input, ['tables' => TableDependencies::CLEANUP_TABLES, 'count_only' => true], $this->user);

    expect($run->status)->toBe(ToolRunStatus::Succeeded)
        ->and(ExportFile::query()->count())->toBe(1)
        ->and(Storage::disk('r2')->allFiles())->toHaveCount(1)
        ->and($run->result['export_file_id'])->toBeNull()
        ->and($run->result['count_only'])->toBeTrue()
        ->and($run->summary['line'])->toBe('Would remove 3 tables and 5 rows, and keep 18 tables and 45 rows. Nothing was written (Count only).');
    Queue::assertNotPushed(SummariseExportFileJob::class);
});

it('gives a zip back for a zip, reading a zip named .xml as a zip', function () {
    $zip = zipOf(trimXml(trimDataset()), 'File_2026_10_04.xml');
    $input = storedTrimInput($this->dataset, ExportKind::Load, $zip, 'load.xml', 'xml');

    $run = runTrimJob($this->dataset, $input, ['tables' => ['Plan', 'Plan_Travel', 'Allocation']], $this->user);
    $trimmed = ExportFile::query()->findOrFail($run->result['export_file_id']);
    $archive = new ZipArchive;
    $archive->open(storedTrimOutput($trimmed));
    $member = tempnam(sys_get_temp_dir(), 'member_');
    file_put_contents($member, $archive->getFromIndex(0));

    expect($trimmed->original_filename)->toBe('load.trimmed.zip')
        ->and($trimmed->content_type)->toBe('zip')
        ->and($archive->numFiles)->toBe(1)
        ->and($archive->getNameIndex(0))->toBe('File_2026_10_04.trimmed.xml')
        ->and(array_keys(trimmedRows($member, 'xml')))->not->toContain('Plan')
        ->and($run->result['zipped'])->toBeTrue()
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
});

it('trims an End of day json export and keeps its kind', function () {
    $input = storedTrimInput($this->dataset, ExportKind::Eod, trimJson(trimDataset()), 'eod.json', 'json');

    $run = runTrimJob($this->dataset, $input, ['region_ids' => ['V1']], $this->user);
    $trimmed = ExportFile::query()->findOrFail($run->result['export_file_id']);

    expect($trimmed->kind)->toBe(ExportKind::Eod)
        ->and($trimmed->original_filename)->toBe('eod.trimmed.json')
        ->and(array_column(trimmedRows(storedTrimOutput($trimmed), 'json')['Activity'], 'id'))->toBe(['A1', 'A3', 'A4']);
});

it('removes the new export row when storing it fails', function () {
    $input = storedTrimInput($this->dataset, ExportKind::Load, trimXml(trimDataset()), 'load.xml', 'xml');
    $this->partialMock(ExportStorage::class, static function ($mock): void {
        $mock->shouldReceive('putFromLocal')->andThrow(new RuntimeException('The file could not be stored.'));
    });

    $run = runTrimJob($this->dataset, $input, ['tables' => ['Plan']], $this->user);

    expect($run->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toBe('The file could not be stored.')
        ->and(ExportFile::query()->count())->toBe(1);
    Queue::assertNotPushed(SummariseExportFileJob::class);
});

it('starts a run on exactly one of LOAD, plan and End of day', function () {
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);
    $plan = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan]);
    $start = app(StartToolRun::class);

    expect(fn () => $start->start($this->dataset, ToolId::TrimExport, [], [], $this->user))
        ->toThrow(UnusableInputException::class, 'Choose one LOAD, Plan or End of day export.');
    expect(fn () => $start->start($this->dataset, ToolId::TrimExport, ['load' => $load->id, 'plan' => $plan->id], [], $this->user))
        ->toThrow(InvalidArgumentException::class, 'Trim export takes one export at a time.');
    expect(ToolRun::query()->count())->toBe(0);

    $run = $start->start($this->dataset, ToolId::TrimExport, ['load' => null, 'plan' => $plan->id], [], $this->user);

    expect($run->inputs['files'])->toBe(['plan' => $plan->id]);
});

it('is wizard only: no Run action for it on the dataset Runs tab', function () {
    Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->assertTableActionDoesNotExist('run_trim_export')
        ->assertTableActionExists('run_'.ToolId::LoadAudit->value);
});

it('refuses a trim that would change nothing, and still allows Count only', function () {
    $input = storedTrimInput($this->dataset, ExportKind::Load, trimXml(trimDataset()), 'load.xml', 'xml');

    $run = runTrimJob($this->dataset, $input, ['table_mode' => 'remove', 'tables' => []], $this->user);

    expect($run->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toBe('Choose tables to remove or rows to keep. Nothing would change.')
        ->and(ExportFile::query()->count())->toBe(1);

    $counted = runTrimJob($this->dataset, $input, ['count_only' => true], $this->user);

    expect($counted->status)->toBe(ToolRunStatus::Succeeded)
        ->and($counted->result['count_only'])->toBeTrue();
});

/** Runs the real summary job on a stored export, synchronously, and gives the row back. */
function summariseTrimmed(ExportFile $file): ExportFile
{
    (new SummariseExportFileJob($file->id))->handle(app(ExportStorage::class), app(ExportSummariser::class));

    return $file->refresh();
}

it('files a plan or End of day export trimmed with the Cleanup preset as a LOAD the summariser reads', function (ExportKind $kind, string $format) {
    $source = $format === 'xml' ? trimXml(trimDataset()) : trimJson(trimDataset());
    $input = storedTrimInput($this->dataset, $kind, $source, 'north.'.$format, $format);

    $run = runTrimJob($this->dataset, $input, ['table_mode' => 'remove', 'tables' => TableDependencies::CLEANUP_TABLES], $this->user);
    $trimmed = summariseTrimmed(ExportFile::query()->findOrFail($run->result['export_file_id']));

    expect($run->status)->toBe(ToolRunStatus::Succeeded)
        ->and($trimmed->kind)->toBe(ExportKind::Load)
        ->and($trimmed->original_filename)->toBe('north.trimmed.'.$format)
        ->and($trimmed->status)->toBe(ExportFileStatus::Ready)
        ->and($trimmed->error_message)->toBeNull()
        ->and($trimmed->summary['dataset_id'])->toBe('NORTH');
})->with([
    'plan xml' => [ExportKind::Plan, 'xml'],
    'End of day json' => [ExportKind::Eod, 'json'],
]);

it('keeps the plan kind when the trim keeps the Plan table, and the summariser reads it as a plan', function () {
    $input = storedTrimInput($this->dataset, ExportKind::Plan, trimXml(trimDataset()), 'north-plan.xml', 'xml');

    $run = runTrimJob($this->dataset, $input, ['tables' => ['Plan_Travel']], $this->user);
    $trimmed = summariseTrimmed(ExportFile::query()->findOrFail($run->result['export_file_id']));

    expect($run->status)->toBe(ToolRunStatus::Succeeded)
        ->and($trimmed->kind)->toBe(ExportKind::Plan)
        ->and($trimmed->original_filename)->toBe('north-plan.trimmed.xml')
        ->and($trimmed->status)->toBe(ExportFileStatus::Ready);
});
