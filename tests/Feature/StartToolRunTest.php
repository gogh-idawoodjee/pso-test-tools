<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Tools\StartToolRun;
use App\Support\Tools\UnusableInputException;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeTool;

beforeEach(function () {
    Queue::fake();
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->actingAs($this->user);
});

it('creates a queued run with the chosen files and options and dispatches the job', function () {
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    $run = app(StartToolRun::class)->start($this->dataset, ToolId::LoadAudit, ['load' => $load->id], ['ifs_release' => '25.2.7'], $this->user);

    expect($run)->toBeInstanceOf(ToolRun::class)
        ->and($run->status)->toBe(ToolRunStatus::Queued)
        ->and($run->scope_id)->toBe($this->dataset->getKey())
        ->and($run->inputs)->toBe(['files' => ['load' => $load->id], 'options' => ['ifs_release' => '25.2.7']])
        ->and($run->started_by_user_id)->toBe($this->user->id);
    Queue::assertPushed(RunToolJob::class, fn (RunToolJob $job): bool => $job->toolRunId === $run->id);
});

it('drops blank optional files and refuses a file that is not usable on this scope', function () {
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);
    $purged = ExportFile::factory()->for($this->dataset->environment, 'scope')->sys()->purged()->create();

    $run = app(StartToolRun::class)->start($this->dataset, ToolId::LoadAudit, ['load' => $load->id, 'sys' => null], [], $this->user);
    expect($run->inputs['files'])->toBe(['load' => $load->id]);

    expect(fn () => app(StartToolRun::class)->start($this->dataset, ToolId::LoadAudit, ['load' => $load->id, 'sys' => $purged->id], [], $this->user))
        ->toThrow(UnusableInputException::class, 'The SYS export you chose is no longer available. Choose or upload another.');
    expect(ToolRun::count())->toBe(1);
    Queue::assertPushed(RunToolJob::class, 1);
});

it('refuses a required file that is missing', function () {
    expect(fn () => app(StartToolRun::class)->start($this->dataset, ToolId::LoadAudit, [], [], $this->user))
        ->toThrow(UnusableInputException::class, 'A LOAD export is required.');
});

it('remembers the context on the user, with a null dataset for an environment-scoped run', function () {
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);
    app(StartToolRun::class)->start($this->dataset, ToolId::LoadAudit, ['load' => $load->id], [], $this->user);
    expect($this->user->fresh()->last_tool_context)->toBe(['environment_id' => $this->dataset->environment_id, 'dataset_id' => $this->dataset->id, 'unfiled' => false, 'time_zone' => null]);

    $sys = ExportFile::factory()->for($this->dataset->environment, 'scope')->sys()->create();
    FakeTool::$kinds = [[ExportKind::Sys], []];
    app(ToolRegistry::class)->register(ToolId::SysCompare, FakeTool::class);
    app(StartToolRun::class)->start($this->dataset->environment, ToolId::SysCompare, ['sys' => $sys->id], [], $this->user);
    FakeTool::$kinds = null;
    expect($this->user->fresh()->last_tool_context)->toBe(['environment_id' => $this->dataset->environment_id, 'dataset_id' => null, 'unfiled' => false, 'time_zone' => null]);
});

it('keeps the remembered time zone when a dataset run follows an unfiled one', function () {
    $this->user->rememberToolContext($this->dataset->environment_id, $this->dataset->id, true, 'America/Regina');
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    app(StartToolRun::class)->start($this->dataset, ToolId::LoadAudit, ['load' => $load->id], [], $this->user);

    expect($this->user->fresh()->last_tool_context)->toBe(['environment_id' => $this->dataset->environment_id, 'dataset_id' => $this->dataset->id, 'unfiled' => false, 'time_zone' => 'America/Regina']);
});

it('checks chosen files are usable, skipping blanks and keys the tool does not take', function () {
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);
    $purged = ExportFile::factory()->for($this->dataset, 'scope')->purged()->create(['kind' => ExportKind::Load]);
    $check = app(StartToolRun::class);

    $check->assertUsable($this->dataset, ToolId::LoadAudit, ['load' => $load->id, 'sys' => null, 'not-a-kind' => 'x']);

    expect(fn () => $check->assertUsable($this->dataset, ToolId::LoadAudit, ['load' => $purged->id]))
        ->toThrow(UnusableInputException::class, 'The LOAD export you chose is no longer available. Choose or upload another.');
});

it('starts an unfiled run on the user and remembers Unfiled, the zone and the last dataset', function () {
    $this->user->rememberToolContext($this->dataset->environment_id, $this->dataset->id);
    $load = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);

    $run = app(StartToolRun::class)->start($this->user, ToolId::LoadAudit, ['load' => $load->id], ['time_zone' => 'America/Regina'], $this->user)->fresh();

    expect($run->scope_type)->toBe($this->user->getMorphClass())
        ->and((string) $run->scope_id)->toBe((string) $this->user->id)
        ->and($run->inputs)->toBe(['files' => ['load' => $load->id], 'options' => ['time_zone' => 'America/Regina']])
        ->and($this->user->fresh()->last_tool_context)->toBe([
            'environment_id' => $this->dataset->environment_id, 'dataset_id' => $this->dataset->id, 'unfiled' => true, 'time_zone' => 'America/Regina',
        ]);
    Queue::assertPushed(RunToolJob::class, fn (RunToolJob $job): bool => $job->toolRunId === $run->id);
});

it('refuses another user unfiled file, or a dataset file, on an unfiled run', function () {
    $theirs = ExportFile::factory()->for(User::factory()->create(), 'scope')->create(['kind' => ExportKind::Load]);
    $datasetLoad = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    foreach ([$theirs, $datasetLoad] as $file) {
        expect(fn () => app(StartToolRun::class)->start($this->user, ToolId::LoadAudit, ['load' => $file->id], [], $this->user))
            ->toThrow(UnusableInputException::class, 'The LOAD export you chose is no longer available. Choose or upload another.');
    }
    expect(ToolRun::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses an unfiled run for a tool that needs a dataset, or on another user', function () {
    app(ToolRegistry::class)->register(ToolId::RegionAvailability, FakeTool::class);
    $load = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);

    expect(fn () => app(StartToolRun::class)->start($this->user, ToolId::RegionAvailability, ['load' => $load->id], [], $this->user))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(StartToolRun::class)->start(User::factory()->create(), ToolId::LoadAudit, ['load' => $load->id], [], $this->user))
        ->toThrow(InvalidArgumentException::class);
    expect(ToolRun::withoutGlobalScopes()->count())->toBe(0);
    Queue::assertNothingPushed();
});
