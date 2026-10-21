<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use App\Tools\Contracts\ToolSetupException;
use App\Tools\LoadAudit\LoadAuditTool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/** Symfony Process only forwards variables from $_SERVER/$_ENV, not ones set with putenv(), so set them there. */
function fakeAuditEnv(string $name, ?string $value): void
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
    config(['tools.pso_tools_path' => base_path('tests/Fixtures/pso-tools'), 'tools.python' => 'python3', 'tools.load_audit.timeout_seconds' => 840]);
    fakeAuditEnv('FAKE_AUDIT_MODE', 'ok');
    $this->scratch = sys_get_temp_dir().'/la-'.uniqid();
    mkdir($this->scratch);
    file_put_contents($this->scratch.'/load.xml', '<dsScheduleData/>');
    $this->dataset = Dataset::factory()->create(['settings' => ['display_name' => 'SaskTel', 'time_zone' => 'America/Regina']]);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'result' => null, 'progress' => null]);
    $this->context = new ToolContext($this->run, $this->dataset, $this->dataset->settings, 'America/Regina');
});

afterEach(function () {
    if (isset($this->scratch) && is_dir($this->scratch)) {
        File::deleteDirectory($this->scratch);
    }

    fakeAuditEnv('FAKE_AUDIT_MODE', null);
    fakeAuditEnv('FAKE_AUDIT_ARGS_FILE', null);
});

it('is registered for the load audit', function () {
    expect(app(ToolRegistry::class)->for(ToolId::LoadAudit))->toBeInstanceOf(LoadAuditTool::class);
});

it('runs the script in the scratch folder and stores html, findings, counts and warnings', function () {
    $argsFile = $this->scratch.'/args.json';
    fakeAuditEnv('FAKE_AUDIT_ARGS_FILE', $argsFile);

    $result = (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], ['ifs_release' => '25.2.7']);

    $args = json_decode(file_get_contents($argsFile), true);
    expect($args['cwd'])->toBe(realpath($this->scratch))
        ->and($args['progress_env'])->toBe('lines')
        ->and($args['argv'])->toContain('--org=SaskTel', '--tz', 'America/Regina', '--ifs-release', '25.2.7')
        ->and(is_dir($this->scratch.'/accounts'))->toBeTrue()
        ->and($result->result['html'])->toContain('<h1>SaskTel</h1>')
        ->and($result->result['findings'])->toHaveCount(3)
        ->and($result->result['counts'])->toBe(['red' => 1, 'amber' => 1, 'green' => 1])
        ->and($result->summary)->toBe(['counts' => ['red' => 1, 'amber' => 1, 'green' => 1], 'system_day' => false, 'system_day_csv' => false])
        ->and($result->warnings)->toBe(['no recommendation for red/amber check: Resources with no shifts']);
});

it('reports progress in plain English as the script runs', function () {
    (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml', 'sys' => $this->scratch.'/load.xml'], []);

    // The last label saved before the job clears it (the tool itself does not clear progress).
    expect($this->run->fresh()->progress['label'])->toBe('Running the checks');
});

it('throttles repeated percent updates for the same stage', function () {
    $saves = 0;
    DB::listen(static function ($query) use (&$saves): void {
        if (str_starts_with($query->sql, 'update `tool_runs`') || str_starts_with($query->sql, 'update "tool_runs"')) {
            $saves++;
        }
    });

    // The script emits load 0, 45, 100 then checks within milliseconds: two saves, not four.
    (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []);

    expect($saves)->toBe(2);
});

it('joins a progress line that arrives in two chunks', function () {
    fakeAuditEnv('FAKE_AUDIT_MODE', 'split');
    $context = new class($this->run, $this->dataset, $this->dataset->settings, 'America/Regina') extends ToolContext
    {
        /** @var list<string> */
        public array $labels = [];

        public function reportProgress(string $label, ?int $percent = null, ?int $secondsLeft = null, ?string $stage = null): void
        {
            $this->labels[] = $label;
            parent::reportProgress($label, $percent, $secondsLeft, $stage);
        }
    };

    (new LoadAuditTool)->run($context, ['load' => $this->scratch.'/load.xml'], []);

    expect($context->labels)->toContain('Reading the SYS file: 50%');
});

it('fails plainly when the server is not set up', function () {
    config(['tools.pso_tools_path' => null]);

    expect(fn () => (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(ToolSetupException::class, 'not set up on this server');
});

it('names the stage and the last error line when the script fails', function () {
    fakeAuditEnv('FAKE_AUDIT_MODE', 'fail');

    expect(fn () => (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(RuntimeException::class, "The audit stopped while running the checks: KeyError: 'dataset_id'");
});

it('fails when the script exits cleanly but wrote nothing', function () {
    fakeAuditEnv('FAKE_AUDIT_MODE', 'silent');

    expect(fn () => (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(RuntimeException::class, 'The audit finished but wrote no results.');
});

it('stops a script that runs too long', function () {
    config(['tools.load_audit.timeout_seconds' => 1]);
    fakeAuditEnv('FAKE_AUDIT_MODE', 'hang');

    expect(fn () => (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(RuntimeException::class, 'The audit took longer than 0 minutes and was stopped.');
});

it('offers the ifs release and grace hours as run options', function () {
    $names = array_map(static fn ($field) => $field->getName(), (new LoadAuditTool)->optionFields());

    expect($names)->toBe(['options.ifs_release', 'options.complete_grace_hours', 'options.noise_event_ids', 'options.flag_activities', 'options.flag_deletions']);
});

it('surfaces a missing python as the not-set-up message', function () {
    config(['tools.python' => '/nonexistent/python3']);

    expect(fn () => (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(ToolSetupException::class, 'The pso-tools checkout is not set up on this server: Python (/nonexistent/python3) was not found.');
});

it('limits the last error line kept in the failure message', function () {
    fakeAuditEnv('FAKE_AUDIT_MODE', 'longfail');

    try {
        (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []);
        $this->fail('expected a failure');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toStartWith('The audit stopped while running the checks: EEE')
            ->and($exception->getMessage())->not->toContain(str_repeat('E', 301))
            ->and(strlen($exception->getMessage()))->toBeLessThan(400);
    }
});

it('removes the whole scratch folder, accounts folder included, after a run', function (string $mode, ToolRunStatus $expected) {
    fakeAuditEnv('FAKE_AUDIT_MODE', $mode);
    Storage::fake('r2');
    $scratchRoot = sys_get_temp_dir().'/exports-la-'.uniqid();
    config(['exports.disk' => 'r2', 'exports.scratch_path' => $scratchRoot]);

    try {
        $storage = app(ExportStorage::class);
        $file = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'content_type' => 'xml']);
        Storage::disk('r2')->put($storage->objectKey($file), '<dsScheduleData/>');
        $run = ToolRun::factory()->for($this->dataset, 'scope')->create([
            'tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Queued, 'result' => null, 'run_date' => null,
            'inputs' => ['files' => ['load' => $file->id], 'options' => []],
        ]);

        (new RunToolJob($run->id))->handle($storage, app(ToolRegistry::class));

        expect($run->fresh()->status)->toBe($expected)
            ->and(is_dir($scratchRoot.'/'.$run->id))->toBeFalse();
    } finally {
        File::deleteDirectory($scratchRoot);
    }
})->with([['ok', ToolRunStatus::Succeeded], ['fail', ToolRunStatus::Failed]]);

it('labels an unfiled audit with the LOAD file name', function () {
    $argsFile = $this->scratch.'/args.json';
    fakeAuditEnv('FAKE_AUDIT_ARGS_FILE', $argsFile);
    $user = User::factory()->create();
    $load = ExportFile::factory()->for($user, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'prod_north_load_1013.xml']);
    $run = ToolRun::factory()->for($user, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'result' => null, 'progress' => null,
        'inputs' => ['files' => ['load' => $load->id], 'options' => ['time_zone' => 'America/Regina']],
    ]);

    (new LoadAuditTool)->run(new ToolContext($run, $user, null, 'America/Regina'), ['load' => $this->scratch.'/load.xml'], []);

    $argv = json_decode(file_get_contents($argsFile), true)['argv'];
    expect($argv)->toContain('--org=prod_north_load_1013.xml')
        ->and($argv[array_search('--tz', $argv, true) + 1])->toBe('America/Regina');
});

it('still passes no label for a dataset with no display name', function () {
    $argsFile = $this->scratch.'/args.json';
    fakeAuditEnv('FAKE_AUDIT_ARGS_FILE', $argsFile);
    $dataset = Dataset::factory()->create(['settings' => ['time_zone' => 'America/Regina']]);
    $load = ExportFile::factory()->for($dataset, 'scope')->create(['kind' => ExportKind::Load, 'original_filename' => 'north_load.xml']);
    $run = ToolRun::factory()->for($dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'result' => null, 'progress' => null, 'inputs' => ['files' => ['load' => $load->id], 'options' => []]]);

    (new LoadAuditTool)->run(new ToolContext($run, $dataset, $dataset->settings, 'America/Regina'), ['load' => $this->scratch.'/load.xml'], []);

    expect(preg_grep('/^--org=/', json_decode(file_get_contents($argsFile), true)['argv']))->toBeEmpty();
});
