<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolRun;
use App\Tools\Contracts\ToolSetupException;
use App\Tools\RegionAvailability\RegionAvailabilityTool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\File;

/** Symfony Process only forwards variables from $_SERVER/$_ENV, not ones set with putenv(), so set them there. */
function fakeViewerEnv(string $name, ?string $value): void
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
    config(['tools.pso_tools_path' => base_path('tests/Fixtures/pso-tools'), 'tools.python' => 'python3', 'tools.region_availability.timeout_seconds' => 600]);
    fakeViewerEnv('FAKE_RA_MODE', 'ok');
    $this->scratch = sys_get_temp_dir().'/ra-'.uniqid();
    mkdir($this->scratch);
    file_put_contents($this->scratch.'/load.xml', '<dsScheduleData/>');
    $this->dataset = Dataset::factory()
        ->for(Environment::factory()->state(['account_id' => 'acme']))
        ->create(['settings' => ['display_name' => 'Acme', 'time_zone' => 'America/Regina', 'attribute_labels' => ['district' => 'District']]]);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::RegionAvailability, 'result' => null, 'progress' => null]);
    $this->context = new ToolContext($this->run, $this->dataset, $this->dataset->settings, 'America/Regina');
});

afterEach(function () {
    if (isset($this->scratch) && is_dir($this->scratch)) {
        File::deleteDirectory($this->scratch);
    }
    fakeViewerEnv('FAKE_RA_MODE', null);
    fakeViewerEnv('FAKE_RA_ARGS_FILE', null);
});

it('is registered for region availability', function () {
    expect(app(ToolRegistry::class)->for(ToolId::RegionAvailability))->toBeInstanceOf(RegionAvailabilityTool::class);
});

it('writes the account file, runs the script in the scratch folder and stores page, data, summary and warnings', function () {
    $argsFile = $this->scratch.'/args.json';
    fakeViewerEnv('FAKE_RA_ARGS_FILE', $argsFile);

    $result = (new RegionAvailabilityTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], ['week_of' => '2026-10-07']);

    $args = json_decode(file_get_contents($argsFile), true);
    expect($args['cwd'])->toBe(realpath($this->scratch))
        ->and($args['progress_env'])->toBe('lines')
        ->and($args['argv'])->toContain('--account', 'ACME', '--time-zone', 'America/Regina', '--week-of', '2026-10-07')
        ->and($args['settings_file'])->toBe($this->scratch.'/accounts/ACME.json')
        ->and($args['settings']['display_name'])->toBe('Acme')
        ->and($args['settings']['attributes'])->toBe(['district' => 'District'])
        ->and($result->result['html'])->toContain('Acme: regions, week of 2026-10-07')
        ->and($result->result['data']['meta']['week'])->toBe('2026-10-07')
        ->and($result->result['data']['meta']['labels']['district'])->toBe('District')
        ->and($result->summary)->toBe([
            'week' => '2026-10-07',
            'counts' => ['techs' => 118, 'shifts' => 500, 'techs_with_changes' => 9],
            'line' => '118 technicians, 9 with changes, week of 2026-10-07',
        ])
        ->and($result->warnings)->toBe(['No shifts in this week.']);
});

it('passes no week when the option is blank and uses ACCOUNT when the environment has no account id', function () {
    Environment::query()->withoutGlobalScopes()->whereKey($this->dataset->environment_id)->update(['account_id' => '']);
    $context = new ToolContext($this->run, $this->dataset, $this->dataset->settings, 'America/Regina');
    $argsFile = $this->scratch.'/args.json';
    fakeViewerEnv('FAKE_RA_ARGS_FILE', $argsFile);

    (new RegionAvailabilityTool)->run($context, ['load' => $this->scratch.'/load.xml'], ['week_of' => '']);

    $args = json_decode(file_get_contents($argsFile), true);
    expect($args['argv'])->not->toContain('--week-of')
        ->and($args['argv'])->toContain('--account', 'ACCOUNT')
        ->and(is_file($this->scratch.'/accounts/ACCOUNT.json'))->toBeTrue();
});

it('leaves the last progress label at the gather stage', function () {
    (new RegionAvailabilityTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []);

    expect($this->run->fresh()->progress['label'])->toBe("Working out each technician's regions");
});

it('names the stage and the last error line when the script fails', function () {
    fakeViewerEnv('FAKE_RA_MODE', 'fail');

    expect(fn () => (new RegionAvailabilityTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(RuntimeException::class, "The viewer stopped while working out each technician's regions: SystemExit: The LOAD has no Input_Reference. Is it a dsScheduleData export?");
});

it('fails when the script exits cleanly but wrote no page', function () {
    fakeViewerEnv('FAKE_RA_MODE', 'nopage');

    expect(fn () => (new RegionAvailabilityTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(RuntimeException::class, 'The viewer finished but wrote no page.');
});

it('stops a script that runs too long', function () {
    config(['tools.region_availability.timeout_seconds' => 1]);
    fakeViewerEnv('FAKE_RA_MODE', 'hang');

    expect(fn () => (new RegionAvailabilityTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(RuntimeException::class, 'The viewer took longer than 0 minutes and was stopped.');
});

it('fails plainly when the server is not set up', function () {
    config(['tools.pso_tools_path' => null]);

    expect(fn () => (new RegionAvailabilityTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []))
        ->toThrow(ToolSetupException::class, 'The pso-tools checkout is not set up on this server: TOOLS_PSO_TOOLS_PATH is not set.');
});

it('offers only the week as a run option and needs only a LOAD', function () {
    $tool = new RegionAvailabilityTool;

    expect(array_map(static fn ($field) => $field->getName(), $tool->optionFields()))->toBe(['options.week_of'])
        ->and($tool->requiredKinds())->toBe([ExportKind::Load])
        ->and($tool->optionalKinds())->toBe([]);
});
