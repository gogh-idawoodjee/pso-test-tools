<?php

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Tools\UnusableRunInputException;
use App\Tools\TravelTuning\SourceRuns;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-tuning-'.uniqid()]);
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->environment = Environment::factory()->for($this->user)->create();
    $this->dataset = Dataset::factory()->for($this->environment)->create(['settings' => ['time_zone' => 'America/Regina']]);
    $this->sys = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['summary' => ['polygon_count' => 251]]);
    $this->actingAs($this->user);
});

function refusal(callable $call): UnusableRunInputException
{
    try {
        $call();
    } catch (UnusableRunInputException $e) {
        return $e;
    }
    throw new RuntimeException('Expected the pick to be refused.');
}

it('lists the finished plan travels runs of the scope, newest first, and says which have Google', function () {
    $older = planTravelsRun($this->dataset, ['created_at' => Carbon::parse('2026-10-13 04:35:00', 'UTC')->setTimezone(config('app.timezone'))]);
    $newer = planTravelsRun($this->dataset, ['created_at' => Carbon::parse('2026-10-14 04:35:00', 'UTC')->setTimezone(config('app.timezone')), 'summary' => ['leg_count' => 3369, 'review_count' => 10, 'first_date' => '2026-10-05', 'last_date' => '2026-10-09', 'google' => null]]);
    planTravelsRun($this->dataset, ['status' => ToolRunStatus::Failed]);
    planTravelsRun(Dataset::factory()->for($this->environment)->create());
    ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit]);

    expect(app(SourceRuns::class)->rows($this->dataset))->toBe([
        ['id' => $newer->id, 'range' => '5 Oct 2026 to 9 Oct 2026', 'made' => '13 Oct 2026 22:35', 'legs' => 3369, 'flagged' => 10, 'google' => false],
        ['id' => $older->id, 'range' => '5 Oct 2026', 'made' => '12 Oct 2026 22:35', 'legs' => 4, 'flagged' => 2, 'google' => true],
    ]);
});

it('checks a pick and fills the default labels from the final order', function () {
    $base = planTravelsRun($this->dataset);
    $middle = planTravelsRun($this->dataset);
    $final = planTravelsRun($this->dataset);

    $checked = app(SourceRuns::class)->check($this->dataset, [
        ['run_id' => $base->id, 'label' => '', 'sys_file_id' => null],
        ['run_id' => $middle->id, 'label' => "  Grid\n  v5 ", 'sys_file_id' => ''],
        ['run_id' => $final->id, 'label' => null, 'sys_file_id' => $this->sys->id],
    ]);

    expect($checked)->toBe([
        ['run_id' => $base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $middle->id, 'label' => 'Grid v5', 'sys_file_id' => null],
        ['run_id' => $final->id, 'label' => 'Run 2', 'sys_file_id' => $this->sys->id],
    ])->and(SourceRuns::label(str_repeat('x', 80), 1))->toHaveLength(60);
});

it('refuses fewer than two runs, more than ten, a blank row and the same run twice', function () {
    $run = planTravelsRun($this->dataset);
    $sources = app(SourceRuns::class);

    expect(refusal(fn () => $sources->check($this->dataset, [['run_id' => $run->id]]))->getMessage())
        ->toBe('Pick at least two plan travels runs: the baseline first, then each tuned run.')
        ->and(refusal(fn () => $sources->check($this->dataset, array_fill(0, 11, ['run_id' => $run->id])))->getMessage())
        ->toBe('Pick at most 10 plan travels runs for one report.')
        ->and(refusal(fn () => $sources->check($this->dataset, [['run_id' => $run->id], ['run_id' => '']]))->row)->toBe(2)
        ->and(refusal(fn () => $sources->check($this->dataset, [['run_id' => $run->id], ['run_id' => $run->id]]))->getMessage())
        ->toBe('Row 2 is the same plan travels run as row 1. Pick each run once.');
});

it('refuses a run without Google, a failed run, and one from another dataset', function () {
    $base = planTravelsRun($this->dataset);
    $noGoogle = planTravelsRun($this->dataset, ['summary' => ['leg_count' => 4, 'review_count' => 0, 'google' => null]]);
    $failed = planTravelsRun($this->dataset, ['status' => ToolRunStatus::Failed]);
    $elsewhere = planTravelsRun(Dataset::factory()->for($this->environment)->create());
    $sources = app(SourceRuns::class);

    expect(refusal(fn () => $sources->check($this->dataset, [['run_id' => $base->id], ['run_id' => $noGoogle->id]]))->getMessage())
        ->toBe('Row 2: that plan travels run has no Google columns. Run plan travels again with Google on.')
        ->and(refusal(fn () => $sources->check($this->dataset, [['run_id' => $failed->id], ['run_id' => $base->id]]))->getMessage())
        ->toBe('Row 1: that plan travels run is no longer available here. Choose another.')
        ->and(refusal(fn () => $sources->check($this->dataset, [['run_id' => $base->id], ['run_id' => $elsewhere->id]]))->row)->toBe(2);
});

it('refuses a SYS export of another environment and one with no Polygon table', function () {
    $base = planTravelsRun($this->dataset);
    $final = planTravelsRun($this->dataset);
    $otherSys = ExportFile::factory()->for(Environment::factory()->for($this->user)->create(), 'scope')->sys()->create(['summary' => ['polygon_count' => 3]]);
    $emptySys = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['summary' => ['polygon_count' => 0]]);
    $sources = app(SourceRuns::class);

    expect(refusal(fn () => $sources->check($this->dataset, [['run_id' => $base->id], ['run_id' => $final->id, 'sys_file_id' => $otherSys->id]]))->getMessage())
        ->toBe('Row 2: the SYS export you chose is no longer available. Choose or upload another.')
        ->and(refusal(fn () => $sources->check($this->dataset, [['run_id' => $base->id], ['run_id' => $final->id, 'sys_file_id' => $emptySys->id]]))->getMessage())
        ->toBe('This SYS export has no Polygon table (row 2). Choose another, or None.');
});

it('takes the user\'s own Unfiled runs and SYS exports, never another user\'s', function () {
    $mine = planTravelsRun($this->user);
    $mineToo = planTravelsRun($this->user);
    $mySys = ExportFile::factory()->for($this->user, 'scope')->sys()->create(['summary' => ['polygon_count' => 1]]);
    $theirs = planTravelsRun(User::factory()->create());
    $sources = app(SourceRuns::class);

    expect($sources->check($this->user, [['run_id' => $mine->id], ['run_id' => $mineToo->id, 'sys_file_id' => $mySys->id]])[1]['sys_file_id'])->toBe($mySys->id)
        ->and(refusal(fn () => $sources->check($this->user, [['run_id' => $mine->id], ['run_id' => $theirs->id]]))->row)->toBe(2)
        ->and(refusal(fn () => $sources->check($this->user, [['run_id' => $mine->id], ['run_id' => $mineToo->id, 'sys_file_id' => $this->sys->id]]))->row)->toBe(2);
});

it('says in one sentence why a SYS export that is not XML cannot be used', function () {
    $json = tempnam(sys_get_temp_dir(), 'sysjson');
    file_put_contents($json, '{"not":"xml"}');
    $file = storedSys($this->environment, $json, attributes: ['content_type' => 'json', 'original_filename' => 'sys.json']);
    @unlink($json);

    expect(app(SourceRuns::class)->sysError($file))->toBe('This SYS export is not XML, so its polygons cannot be read.');
});
