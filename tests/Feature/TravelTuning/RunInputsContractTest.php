<?php

use App\Enums\Tool as ToolId;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Tools\StartToolRun;
use App\Support\Tools\UnusableRunInputException;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRunInputTool;
use Tests\Support\FakeTool;

beforeEach(function () {
    Queue::fake();
    app(ToolRegistry::class)->register(ToolId::TravelTuning, FakeRunInputTool::class);
    FakeRunInputTool::$seen = null;
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->actingAs($this->user);
});

afterEach(function () {
    FakeTool::$kinds = null;
});

it('stores the runs the tool checked, in order, and dispatches the job', function () {
    $run = app(StartToolRun::class)->start($this->dataset, ToolId::TravelTuning, [], ['title' => 'T'], $this->user, [['run_id' => 'b'], ['run_id' => 'a']]);

    expect($run->inputs)->toBe(['files' => [], 'options' => [], 'runs' => [['run_id' => 'b'], ['run_id' => 'a']]])
        ->and($run->options())->toBe(['title' => 'T'])
        ->and($run->sourceRuns())->toBe([['run_id' => 'b'], ['run_id' => 'a']])
        ->and(FakeRunInputTool::$seen)->toBe([['run_id' => 'b'], ['run_id' => 'a']]);
    Queue::assertPushed(RunToolJob::class, fn (RunToolJob $job): bool => $job->toolRunId === $run->id);
});

it('creates no run when the tool refuses the runs', function () {
    expect(fn () => app(StartToolRun::class)->start($this->dataset, ToolId::TravelTuning, [], [], $this->user, [['run_id' => 'a']]))
        ->toThrow(UnusableRunInputException::class, 'Pick at least two runs.');

    expect(ToolRun::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses runs for a tool that takes export files', function () {
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    FakeTool::$kinds = [[], []];

    expect(fn () => app(StartToolRun::class)->start($this->dataset, ToolId::LoadAudit, [], [], $this->user, [['run_id' => 'a']]))
        ->toThrow(InvalidArgumentException::class, 'Load Health Audit does not take other runs as inputs.');
    expect(ToolRun::count())->toBe(0);
});

it('runs on Unfiled, since Travel tuning accepts it', function () {
    $run = app(StartToolRun::class)->start($this->user, ToolId::TravelTuning, [], [], $this->user, [['run_id' => 'a'], ['run_id' => 'b']]);

    expect($run->scope_type)->toBe($this->user->getMorphClass())
        ->and((string) $run->scope_id)->toBe((string) $this->user->getKey());
});

it('passes the runs on when a run is started again', function () {
    $first = app(StartToolRun::class)->start($this->dataset, ToolId::TravelTuning, [], ['title' => 'T'], $this->user, [['run_id' => 'a'], ['run_id' => 'b']]);

    $again = app(StartToolRun::class)->rerun($first, ['title' => 'U'], $this->user);

    expect($again->inputs['runs'])->toBe([['run_id' => 'a'], ['run_id' => 'b']])
        ->and($again->options())->toBe(['title' => 'U']);
});

it('keeps typed free text out of the plain inputs column and reads it back', function () {
    $run = app(StartToolRun::class)->start($this->dataset, ToolId::TravelTuning, [], ['title' => 'Acme corridor review', 'tiles' => 'esri-gray'], $this->user, [
        ['run_id' => 'a', 'label' => 'Acme baseline'],
        ['run_id' => 'b', 'label' => 'Grid, "v6" for Acme'],
    ]);

    $raw = DB::table('tool_runs')->where('id', $run->id)->first();
    expect($raw->inputs)->not->toContain('Acme')
        ->and(json_decode($raw->inputs, true))->toBe(['files' => [], 'options' => ['tiles' => 'esri-gray'], 'runs' => [['run_id' => 'a'], ['run_id' => 'b']]])
        ->and($raw->private_inputs)->toBeString()->not->toContain('Acme')
        ->and($run->fresh()->options())->toBe(['tiles' => 'esri-gray', 'title' => 'Acme corridor review'])
        ->and($run->fresh()->sourceRuns())->toBe([
            ['run_id' => 'a', 'label' => 'Acme baseline'],
            ['run_id' => 'b', 'label' => 'Grid, "v6" for Acme'],
        ]);
});

it('restores the labels and the title when a run is started again', function () {
    $first = app(StartToolRun::class)->start($this->dataset, ToolId::TravelTuning, [], ['title' => 'Acme corridor review'], $this->user, [
        ['run_id' => 'a', 'label' => 'Acme baseline'],
        ['run_id' => 'b', 'label' => 'Run 1'],
    ]);

    $again = app(StartToolRun::class)->rerun($first->fresh(), [], $this->user)->fresh();

    expect(FakeRunInputTool::$seen)->toBe([['run_id' => 'a', 'label' => 'Acme baseline'], ['run_id' => 'b', 'label' => 'Run 1']])
        ->and($again->options())->toBe(['title' => 'Acme corridor review'])
        ->and($again->sourceRuns())->toBe([['run_id' => 'a', 'label' => 'Acme baseline'], ['run_id' => 'b', 'label' => 'Run 1']])
        ->and(DB::table('tool_runs')->where('id', $again->id)->value('inputs'))->not->toContain('Acme');
});

it('stores the inputs of a tool that keeps nothing private as before', function () {
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    FakeTool::$kinds = [[], []];

    $run = app(StartToolRun::class)->start($this->dataset, ToolId::LoadAudit, [], ['week_of' => '2026-10-12'], $this->user);

    expect($run->inputs)->toBe(['files' => [], 'options' => ['week_of' => '2026-10-12']])
        ->and(DB::table('tool_runs')->where('id', $run->id)->value('private_inputs'))->toBeNull()
        ->and($run->fresh()->options())->toBe(['week_of' => '2026-10-12'])
        ->and($run->fresh()->sourceRuns())->toBe([]);
});

it('never selects the encrypted inputs for lists', function () {
    expect(ToolRun::query()->withoutResult()->toSql())->not->toContain('private_inputs');
});
