<?php

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\ToolRegistry;
use App\Tools\TravelTuning\TravelTuningCommand;
use App\Tools\TravelTuning\TravelTuningTool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * Runs the real travel_tuning.py. Needs a pso-tools checkout:
 *   TOOLS_PSO_TOOLS_PATH=/path/to/pso-tools php artisan test tests/Feature/TravelTuning/TravelTuningScriptTest.php
 * The golden also needs the 2026-10-13 customer folder (customer data, keep it outside this repo):
 *   TRAVEL_TUNING_SAMPLE_DIR=a folder with the three golden files
 * Skipped otherwise.
 */

function embeddedTuningData(string $html): array
{
    preg_match('#<script type="application/json" id="data">(.*?)</script>#s', $html, $match);

    return json_decode($match[1] ?? 'null', true) ?? [];
}

beforeEach(function () {
    $this->checkoutPath = env('TOOLS_PSO_TOOLS_PATH');
    if (blank($this->checkoutPath)) {
        $this->markTestSkipped('Set TOOLS_PSO_TOOLS_PATH to run the real travel tuning script.');
    }
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-tuning-real-'.uniqid(), 'tools.pso_tools_path' => $this->checkoutPath]);
    $this->travelTo(Carbon::parse('2026-10-18 15:30:00', 'UTC'));
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->environment = Environment::factory()->for($this->user)->create(['account_id' => 'ACCT']);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH', 'settings' => ['time_zone' => 'America/Regina', 'display_name' => 'Client']]);
});

afterEach(function () {
    File::deleteDirectory((string) config('exports.scratch_path'));
    // The fake disk can hold a copy of a customer SYS export (live credentials); never leave it under storage/.
    Storage::disk('r2')->deleteDirectory('exports');
});

it('builds the report from synthetic runs and a zipped SYS export with the real script', function () {
    $base = planTravelsRun($this->dataset);
    $final = planTravelsRun($this->dataset, csv: tuningCsv([960, 1560, 620, 880]));
    $sys = storedSys($this->environment, polygonSysXml(1), zipped: true);
    $run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::TravelTuning, 'status' => ToolRunStatus::Queued, 'result' => null, 'summary' => null, 'run_date' => null,
        ...ToolRun::inputAttributes(app(TravelTuningTool::class), ['files' => [], 'options' => ['title' => 'Travel tuning test', 'client' => 'Client', 'dataset' => 'North'], 'runs' => [
            ['run_id' => $base->id, 'label' => 'Baseline', 'sys_file_id' => null],
            ['run_id' => $final->id, 'label' => 'Grid v1', 'sys_file_id' => $sys->id],
        ]]),
    ]);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));
    $run->refresh();

    $data = embeddedTuningData($run->result['html'] ?? '');
    expect($run->status)->toBe(ToolRunStatus::Succeeded, (string) $run->error_message)
        ->and($run->summary)->toBe([
            'runs' => 2, 'baseline_flagged' => 2, 'final_flagged' => 0, 'baseline_error_hours' => 0.8, 'final_error_hours' => 0.1,
            'fixed' => 2, 'newly_broken' => 0, 'shared_legs' => 4, 'first_date' => '2026-10-05',
            'line' => '2 runs: flagged 2 to 0, total error 0.8 to 0.1 hours, 2 fixed, 0 newly broken, 4 shared legs',
        ])
        ->and($run->warnings)->toBe([])
        ->and($run->result['html'])->toContain('<title>Travel tuning test</title>')
        ->and($data['meta']['generated'])->toBe('2026-10-18 09:30')
        ->and($data['meta']['labels'])->toBe(['Baseline', 'Grid v1'])
        ->and(array_column($data['polygons'], 'id'))->toBe(['G01'])
        ->and(glob(config('exports.scratch_path').'/*'))->toBe([]);
});

it('matches the command line on the 2026-10-13 customer runs', function () {
    $sample = env('TRAVEL_TUNING_SAMPLE_DIR');
    if (blank($sample) || ! is_dir($sample)) {
        $this->markTestSkipped('Set TRAVEL_TUNING_SAMPLE_DIR to the 2026-10-13 customer folder to run the golden test.');
    }
    $initial = "{$sample}/plan_travels_output_north_initial.csv";
    $pass2 = "{$sample}/plan_travels_output_pass2.csv";
    $polygons = "{$sample}/PSO_Sys_pass_1.xml";
    $base = planTravelsRun($this->dataset, csv: (string) file_get_contents($initial));
    $final = planTravelsRun($this->dataset, csv: (string) file_get_contents($pass2));
    $sys = storedSys($this->environment, $polygons);
    $options = ['title' => 'Travel Tuning Report', 'client' => 'Client', 'dataset' => 'North'];
    $run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::TravelTuning, 'status' => ToolRunStatus::Queued, 'result' => null, 'summary' => null, 'run_date' => null,
        ...ToolRun::inputAttributes(app(TravelTuningTool::class), ['files' => [], 'options' => $options, 'runs' => [
            ['run_id' => $base->id, 'label' => 'Prod (no polygons)', 'sys_file_id' => null],
            ['run_id' => $final->id, 'label' => 'Grid v6', 'sys_file_id' => $sys->id],
        ]]),
    ]);

    (new RunToolJob($run->id))->handle(app(ExportStorage::class), app(ToolRegistry::class));
    $run->refresh();
    expect($run->status)->toBe(ToolRunStatus::Succeeded, (string) $run->error_message);

    // The command line on the original files, with the same flags and generated time.
    $work = sys_get_temp_dir().'/golden-tuning-cli-'.uniqid();
    mkdir($work, 0770, true);
    try {
        file_put_contents("{$work}/manifest.csv", "Label,RunCsv,PolygonXml\nProd (no polygons),{$initial},\nGrid v6,{$pass2},{$polygons}\n");
        $argv = TravelTuningCommand::build(PsoToolsCheckout::fromConfig(), $work, $options, '2026-10-18 09:30', false);
        $cli = Process::path($work)->timeout(600)->run($argv);
        expect($cli->successful())->toBeTrue($cli->errorOutput());
        $cliData = json_decode((string) file_get_contents("{$work}/travel_tuning.json"), true);
    } finally {
        File::deleteDirectory($work);
    }

    $webData = embeddedTuningData($run->result['html']);
    // File names differ (run-1.csv against the original names); everything else must be identical.
    foreach ([&$webData, &$cliData] as &$data) {
        unset($data['meta']['files']);
        foreach ($data['runs'] as &$runRow) {
            unset($runRow['file']);
        }
        unset($runRow);
    }
    unset($data);

    expect($webData)->toEqual($cliData)
        ->and($run->summary['baseline_flagged'])->toBe(75)
        ->and($run->summary['final_flagged'])->toBe(10)
        ->and($run->summary['fixed'])->toBe(44)
        ->and($run->summary['newly_broken'])->toBe(0)
        ->and($run->summary['shared_legs'])->toBe(2112);
});
