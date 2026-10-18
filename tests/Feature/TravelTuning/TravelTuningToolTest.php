<?php

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use App\Tools\TravelTuning\TravelTuningTool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

function tuningCalls(string $file): array
{
    return is_file($file) ? array_map(static fn (string $line): array => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
}

function tuningEnv(string $name, ?string $value): void
{
    if ($value === null) {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);

        return;
    }
    $_SERVER[$name] = $_ENV[$name] = $value;
    putenv("{$name}={$value}");
}

beforeEach(function () {
    Storage::fake('r2');
    config([
        'exports.disk' => 'r2', 'exports.scratch_path' => realpath(sys_get_temp_dir()).'/exports-tuning-'.uniqid(),
        'tools.pso_tools_path' => base_path('tests/Fixtures/pso-tools'), 'tools.python' => 'python3',
    ]);
    $this->argsFile = sys_get_temp_dir().'/tuning-calls-'.uniqid().'.jsonl';
    tuningEnv('FAKE_TUNING_ARGS_FILE', $this->argsFile);
    $this->travelTo(Carbon::parse('2026-10-18 15:30:00', 'UTC'));
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->environment = Environment::factory()->for($this->user)->create(['account_id' => 'SATE']);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH', 'settings' => ['time_zone' => 'America/Regina', 'display_name' => 'SaskTel']]);
    $this->base = planTravelsRun($this->dataset);
    $this->final = planTravelsRun($this->dataset, csv: tuningCsv([960, 1560, 620, 880]));
    $this->sys = storedSys($this->environment, polygonSysXml(2), zipped: true);
});

afterEach(function () {
    File::deleteDirectory((string) config('exports.scratch_path'));
    @unlink($this->argsFile);
    tuningEnv('FAKE_TUNING_ARGS_FILE', null);
    tuningEnv('FAKE_TUNING_MODE', null);
});

/** A queued tuning run stored the way StartToolRun stores one: labels and typed options in the encrypted private_inputs. */
function tuningRun(Model $scope, array $runs, array $options = []): ToolRun
{
    return ToolRun::factory()->for($scope, 'scope')->create([
        'tool' => ToolId::TravelTuning, 'status' => ToolRunStatus::Queued, 'result' => null, 'summary' => null, 'run_date' => null,
        ...ToolRun::inputAttributes(app(TravelTuningTool::class), [
            'files' => [], 'options' => ['title' => 'Travel Tuning Report', 'client' => 'SaskTel', 'dataset' => 'NORTH', ...$options], 'runs' => $runs,
        ]),
    ]);
}

function runTuningJob(ToolRun $run): ToolRun
{
    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));

    return $run->refresh();
}

it('is registered as a wizard-only tool that takes runs', function () {
    $tool = app(ToolRegistry::class)->for(ToolId::TravelTuning);

    expect($tool)->toBeInstanceOf(TravelTuningTool::class)
        ->and($tool->requiredKinds())->toBe([])
        ->and($tool->optionalKinds())->toBe([])
        ->and($tool->needs())->toBe('Needs two or more Plan travels runs checked with Google')
        ->and($tool->privateOptionKeys())->toBe(['title', 'client', 'dataset', 'notes'])
        ->and($tool->privateRunKeys())->toBe(['label']);
});

it('writes the runs, the polygons and the manifest, runs the script and keeps the page with counts only', function () {
    $run = runTuningJob(tuningRun($this->dataset, [
        ['run_id' => $this->base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $this->final->id, 'label' => 'Grid, "v6"', 'sys_file_id' => $this->sys->id],
    ]));

    $call = tuningCalls($this->argsFile)[0];
    $scratch = config('exports.scratch_path').'/'.$run->id;
    expect($run->status)->toBe(ToolRunStatus::Succeeded, (string) $run->error_message)
        ->and($call['cwd'])->toBe($scratch)
        ->and($call['argv'])->toContain('--manifest', $scratch.'/manifest.csv', '--generated', '2026-10-18 09:30', '--client=SaskTel', '--tiles=esri-gray')
        ->and(implode(' ', $call['argv']))->not->toContain('--notes')
        ->and($call['rows'])->toBe([
            ['label' => 'Baseline', 'run_csv' => 'run-1.csv', 'header' => strtok(tuningCsv(), "\n"), 'polygon_xml' => ''],
            ['label' => 'Grid, "v6"', 'run_csv' => 'run-2.csv', 'header' => strtok(tuningCsv(), "\n"), 'polygon_xml' => 'polygons-2.xml', 'polygon_rows' => 2],
        ])
        ->and($run->result)->toHaveKeys(['html'])->toHaveCount(1)
        ->and($run->result['html'])->toContain('Fake travel tuning page')
        // The script names the labels in its warnings; the plain column gets Run N (baseline Run 1) in their place.
        ->and($run->warnings)->toBe(['Legs common to every run are 55% of the smallest run.', 'Model check Run 1 to Run 2: 30 of 44 legs moved as predicted (68%).'])
        ->and($run->summary)->toBe([
            'runs' => 2, 'baseline_flagged' => 75, 'final_flagged' => 10, 'baseline_error_hours' => 138.2, 'final_error_hours' => 98.2,
            'fixed' => 44, 'newly_broken' => 0, 'shared_legs' => 2112, 'first_date' => '2026-10-05',
            'line' => '2 runs: flagged 75 to 10, total error 138.2 to 98.2 hours, 44 fixed, 0 newly broken, 2,112 shared legs',
        ])
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([])
        ->and(Storage::disk('r2')->allFiles())->toBe([app(ExportStorage::class)->objectKey($this->sys)])
        // The label and client reached the script from the encrypted column; the plain one never held them.
        ->and(DB::table('tool_runs')->where('id', $run->id)->value('inputs'))->not->toContain('Grid')->not->toContain('SaskTel');
});

it('writes the notes for the script when there are some, from the encrypted inputs', function () {
    $run = runTuningJob(tuningRun($this->dataset, [
        ['run_id' => $this->base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $this->final->id, 'label' => 'Run 1', 'sys_file_id' => null],
    ], ['notes' => "## Round 2\n- **Corridors** tightened"]));

    $call = tuningCalls($this->argsFile)[0];
    expect($call['argv'])->toContain('--notes')
        ->and($call['notes'])->toBe("## Round 2\n- **Corridors** tightened\n")
        ->and(DB::table('tool_runs')->where('id', $run->id)->value('inputs'))->not->toContain('Corridors');
});

it('fails in plain words when the script stops, and deletes the SYS copy at once', function () {
    tuningEnv('FAKE_TUNING_MODE', 'fail');
    $run = tuningRun($this->dataset, [
        ['run_id' => $this->base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $this->final->id, 'label' => 'Run 1', 'sys_file_id' => $this->sys->id],
    ]);
    $scratch = app(ExportStorage::class)->scratchDirectoryFor($run->id);
    $context = new ToolContext($run, $this->dataset, $this->dataset->settings, 'America/Regina');

    expect(fn () => app(TravelTuningTool::class)->run($context, [], $run->options()))
        ->toThrow(RuntimeException::class, 'The travel tuning report stopped while reading Run 2: Run 2 (run-2.csv) has no Google columns.');
    expect(glob($scratch.'/polygons-*'))->toBe([])
        ->and(is_file($scratch.'/run-2.csv'))->toBeTrue();
});

it('fails before the script when a source run has gone, naming the row', function () {
    $run = tuningRun($this->dataset, [
        ['run_id' => $this->base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $this->final->id, 'label' => 'Run 1', 'sys_file_id' => null],
    ]);
    $this->final->delete();

    $run = runTuningJob($run);

    expect($run->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toBe('Row 2: the plan travels run is no longer available, so the report cannot be built. Start again with runs that are still listed.')
        ->and(tuningCalls($this->argsFile))->toBe([]);
});

it('fails before the script when the SYS export has expired', function () {
    $this->sys->update(['expires_at' => now()->subMinute()]);

    $run = runTuningJob(tuningRun($this->dataset, [
        ['run_id' => $this->base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $this->final->id, 'label' => 'Run 1', 'sys_file_id' => $this->sys->id],
    ]));

    expect($run->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_message)->toBe('Row 2: the SYS export chosen for the polygons is no longer available (exports are kept for 12 hours). Upload it again and start a new run.')
        ->and(tuningCalls($this->argsFile))->toBe([]);
});

it('runs Unfiled on the user\'s own runs and SYS export, and refuses an environment\'s SYS there', function () {
    $mine = planTravelsRun($this->user);
    $mineToo = planTravelsRun($this->user, csv: tuningCsv([960, 1560, 620, 880]));
    $mySys = storedSys($this->user, polygonSysXml(1));

    $ok = runTuningJob(tuningRun($this->user, [
        ['run_id' => $mine->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $mineToo->id, 'label' => 'Run 1', 'sys_file_id' => $mySys->id],
    ], ['time_zone' => 'America/Toronto']));
    $refused = runTuningJob(tuningRun($this->user, [
        ['run_id' => $mine->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $mineToo->id, 'label' => 'Run 1', 'sys_file_id' => $this->sys->id],
    ]));

    expect($ok->status)->toBe(ToolRunStatus::Succeeded, (string) $ok->error_message)
        ->and(tuningCalls($this->argsFile)[0]['argv'])->toContain('--generated', '2026-10-18 11:30')
        ->and($refused->status)->toBe(ToolRunStatus::Failed)
        ->and($refused->error_message)->toBe("Row 2: the SYS export chosen for the polygons does not belong to this run's environment or unfiled files.");
});

it('says so when the script finishes without a page', function () {
    tuningEnv('FAKE_TUNING_MODE', 'silent');

    $run = runTuningJob(tuningRun($this->dataset, [
        ['run_id' => $this->base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $this->final->id, 'label' => 'Run 1', 'sys_file_id' => null],
    ]));

    expect($run->error_message)->toBe('The travel tuning report finished but wrote no page.');
});

it('puts Run N in place of the labels in the warnings, the failure and the log, longest label first', function () {
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });
    $rows = [
        ['run_id' => $this->base->id, 'label' => 'Acme North', 'sys_file_id' => null],
        ['run_id' => $this->final->id, 'label' => 'Acme North v2', 'sys_file_id' => null],
    ];

    $ok = runTuningJob(tuningRun($this->dataset, $rows));
    tuningEnv('FAKE_TUNING_MODE', 'fail');
    $failed = runTuningJob(tuningRun($this->dataset, $rows));

    $plain = static fn (ToolRun $run): string => json_encode(DB::table('tool_runs')->where('id', $run->id)->first([
        'inputs', 'summary', 'warnings', 'error_message', 'progress',
    ]));
    expect($ok->status)->toBe(ToolRunStatus::Succeeded, (string) $ok->error_message)
        ->and($ok->warnings)->toBe(['Legs common to every run are 55% of the smallest run.', 'Model check Run 1 to Run 2: 30 of 44 legs moved as predicted (68%).'])
        ->and($ok->result['html'])->toContain('Acme North v2')
        ->and($plain($ok))->not->toContain('Acme')
        ->and($failed->status)->toBe(ToolRunStatus::Failed)
        ->and($failed->error_message)->toStartWith('The travel tuning report stopped while reading Run 2: Run 2 (run-2.csv) has no Google columns.')
        ->and($plain($failed))->not->toContain('Acme')
        ->and(implode("\n", $logged))->toContain('Run 2 (run-2.csv)')->not->toContain('Acme');
});
