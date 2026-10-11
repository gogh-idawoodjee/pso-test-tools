<?php

use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\ToolRun;
use App\Tools\Contracts\ToolSetupException;
use App\Tools\LoadAudit\LoadAuditTool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\DB;

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
        ->and($args['argv'])->toContain('--org', 'SaskTel', '--tz', 'America/Regina', '--ifs-release', '25.2.7')
        ->and(is_dir($this->scratch.'/accounts'))->toBeTrue()
        ->and($result->result['html'])->toContain('<h1>SaskTel</h1>')
        ->and($result->result['findings'])->toHaveCount(3)
        ->and($result->result['counts'])->toBe(['red' => 1, 'amber' => 1, 'green' => 1])
        ->and($result->summary)->toBe(['counts' => ['red' => 1, 'amber' => 1, 'green' => 1]])
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

    expect($names)->toBe(['options.ifs_release', 'options.complete_grace_hours']);
});
