<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Support\Exports\ExportStorage;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeTool;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-test-'.uniqid()]);
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    $this->dataset = Dataset::factory()->create(['settings' => ['display_name' => 'SaskTel', 'time_zone' => 'America/Regina']]);
    $this->load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'xml', 'summary' => ['export_date' => '2026-10-10']]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($this->load), '<dsScheduleData><Activity/></dsScheduleData>');
});

function runFor(Dataset $dataset, ExportFile $load, array $options = []): ToolRun
{
    return ToolRun::factory()->for($dataset, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Queued, 'result' => null, 'run_date' => null,
        'inputs' => ['files' => ['load' => $load->id], 'options' => $options],
    ]);
}

it('streams the inputs to scratch, runs the tool with the dataset settings, stores result and warnings, cleans up', function () {
    $run = runFor($this->dataset, $this->load, ['flag' => true]);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    $run->refresh();
    expect($run->status)->toBe(ToolRunStatus::Succeeded)
        ->and($run->result['seen_kinds'])->toBe(['load'])
        ->and($run->result['bytes'])->toBe(strlen('<dsScheduleData><Activity/></dsScheduleData>'))
        ->and($run->result['display_name'])->toBe('SaskTel')
        ->and($run->result['time_zone'])->toBe('America/Regina')
        ->and($run->result['options'])->toBe(['flag' => true])
        ->and($run->warnings)->toBe(['Fake warning'])
        ->and($run->run_date->toDateString())->toBe('2026-10-10')
        ->and($run->finished_at)->not->toBeNull()
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
});

it('refuses to run against a purged or missing input in plain words', function () {
    $this->load->forceFill(['status' => ExportFileStatus::Purged])->save();
    $run = runFor($this->dataset, $this->load);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    expect($run->refresh()->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toContain('no longer available');
});

it('fails the run when a required kind is not supplied', function () {
    $run = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Queued, 'result' => null, 'inputs' => ['files' => [], 'options' => []]]);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    expect($run->refresh()->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toContain('LOAD');
});

it('records a tool exception as a failed run and still cleans scratch', function () {
    FakeTool::$throw = new RuntimeException('boom');
    $run = runFor($this->dataset, $this->load);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));
    FakeTool::$throw = null;

    expect($run->refresh()->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toContain('boom')
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
});

it('refuses an input that belongs to another dataset', function () {
    $foreign = ExportFile::factory()->for(Dataset::factory(), 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'xml']);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($foreign), '<dsScheduleData/>');
    $run = runFor($this->dataset, $foreign);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    expect($run->refresh()->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toContain('does not belong');
});
