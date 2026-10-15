<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Filament\Pages\ToolRunPage;
use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Filament\Resources\DatasetResource\RelationManagers\ToolRunsRelationManager as DatasetRuns;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\RunInputPicker;
use App\Tools\ToolRegistry;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeTool;

beforeEach(function () {
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('preselects the newest set of one day and offers the environment sys for a dataset tool', function () {
    $oldLoad = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['export_date' => '2026-10-09'], 'created_at' => now()->subDay()]);
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['export_date' => '2026-10-10']]);
    $plan = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan, 'summary' => ['export_date' => '2026-10-10']]);
    $sys = ExportFile::factory()->for($this->dataset->environment, 'scope')->sys()->create();
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'status' => ExportFileStatus::Purged]);

    $picker = app(RunInputPicker::class);
    expect(array_keys($picker->optionsFor($this->dataset, ToolId::LoadAudit)))->toBe(['load', 'sys'])
        ->and(array_keys($picker->optionsFor($this->dataset, ToolId::LoadAudit)['load']))->toBe([$load->id, $oldLoad->id])
        ->and($picker->defaultsFor($this->dataset, ToolId::LoadAudit))->toBe(['load' => $load->id, 'sys' => $sys->id]);
});

it('dates audit messages and event logs in the dataset zone, preselects the LOAD day and counts events', function () {
    $this->dataset->update(['settings' => ['time_zone' => 'America/Regina']]);
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['export_date' => '2026-10-10'], 'created_at' => now()->subHours(3)]);
    // 03:00 UTC on the 11th is the evening of the 10th in Regina.
    $sameDayAudits = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Audits, 'summary' => ['message_count' => 9, 'first_message_at' => '2026-10-11T03:00:00+00:00'], 'created_at' => now()->subHours(2)]);
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Audits, 'summary' => ['message_count' => 4, 'first_message_at' => '2026-10-12T15:00:00+00:00']]);
    $sameDayLog = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::EventLog, 'content_type' => 'xlsx', 'summary' => ['event_count' => 1234, 'first_event' => '2026-10-10T14:00:00+00:00'], 'created_at' => now()->subHours(2)]);
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::EventLog, 'content_type' => 'xlsx', 'summary' => ['event_count' => 5, 'first_event' => '2026-10-09T14:00:00+00:00']]);

    $picker = app(RunInputPicker::class);

    expect($sameDayAudits->exportDate())->toBe('2026-10-10')
        ->and($picker->defaultsFor($this->dataset, ToolId::AppointmentTimeline))->toBe(['audits' => $sameDayAudits->id, 'load' => $load->id, 'event_log' => $sameDayLog->id])
        ->and($picker->optionsFor($this->dataset, ToolId::AppointmentTimeline)['event_log'][$sameDayLog->id])->toContain('(1,234 events)');
});

it('starts a run from the Runs tab with the chosen files', function () {
    Queue::fake();
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['export_date' => '2026-10-10']]);

    Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->callTableAction('run_load_audit', data: ['files' => ['load' => $load->id]])
        ->assertHasNoTableActionErrors();

    $run = ToolRun::first();
    expect($run->tool)->toBe(ToolId::LoadAudit)
        ->and($run->status)->toBe(ToolRunStatus::Queued)
        ->and($run->inputs['files']['load'])->toBe($load->id);
    Queue::assertPushed(RunToolJob::class, fn ($job) => $job->toolRunId === $run->id);
});

it('shows a tool option field on the Run form and stores the option', function () {
    Queue::fake();
    FakeTool::$optionFields = [TextInput::make('options.ifs_release')->label('IFS release')];
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['export_date' => '2026-10-10']]);

    Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->callTableAction('run_load_audit', data: ['files' => ['load' => $load->id], 'options' => ['ifs_release' => '25.2.7']])
        ->assertHasNoTableActionErrors();
    FakeTool::$optionFields = [];

    expect(ToolRun::first()->inputs['options'])->toBe(['ifs_release' => '25.2.7']);
});

it('shows a run page with inputs, warnings and the generic result view', function () {
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'morning-load.zip']);
    $run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::ScheduleReview, 'inputs' => ['files' => ['load' => $load->id], 'options' => []],
        'result' => ['checks' => 12], 'warnings' => ['Two activities have no location'],
    ]);

    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $run->id])
        ->assertOk()
        ->assertSee('morning-load.zip')
        ->assertSee('Two activities have no location')
        ->assertSee('checks');
});

it('does not open another user run', function () {
    $run = ToolRun::factory()->for(Dataset::factory(), 'scope')->create();

    expect(fn () => Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $run->scope_id, 'run' => $run->id]))
        ->toThrow(ModelNotFoundException::class);
});

it('tells an empty Runs tab to start from a tool wizard', function () {
    Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->assertSee('Start a run from a tool under Analyse exports');
});
