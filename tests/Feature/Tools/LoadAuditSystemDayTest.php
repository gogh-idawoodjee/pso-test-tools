<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\PsoMessageText;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\RunInputPicker;
use App\Support\Tools\ToolRunFiles;
use App\Tools\LoadAudit\LoadAuditTool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/** Symfony Process only forwards variables from $_SERVER/$_ENV, so set them there. */
function systemDayEnv(string $name, ?string $value): void
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
    config([
        'tools.pso_tools_path' => base_path('tests/Fixtures/pso-tools'), 'tools.python' => 'python3',
        'exports.disk' => 'r2',
    ]);
    Storage::fake('r2');
    systemDayEnv('FAKE_AUDIT_MODE', 'ok');
    $this->scratch = sys_get_temp_dir().'/sd-'.uniqid();
    mkdir($this->scratch);
    foreach (['load.xml', 'audits.zip', 'events.json', 'history.csv'] as $name) {
        file_put_contents($this->scratch.'/'.$name, 'x');
    }
    $this->user = User::factory()->create();
    $this->run = ToolRun::factory()->for($this->user, 'scope')->create(['tool' => ToolId::LoadAudit, 'result' => null, 'progress' => null]);
    $this->context = new ToolContext($this->run, $this->user, null, 'America/Regina');
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
    systemDayEnv('FAKE_AUDIT_MODE', null);
    systemDayEnv('FAKE_AUDIT_ARGS_FILE', null);
});

it('runs the System day leg: stores its page data and inputs CSV and writes messages.json to the scratch folder', function () {
    $argsFile = $this->scratch.'/args.json';
    systemDayEnv('FAKE_AUDIT_ARGS_FILE', $argsFile);
    foreach ([3041, 1767, 2959, 1964, 1965] as $messageId) {
        PsoMessageText::create(['language_id' => 'en', 'message_id' => $messageId, 'text' => "Message {$messageId}"]);
    }

    $result = (new LoadAuditTool)->run($this->context, [
        'load' => $this->scratch.'/load.xml', 'audits' => $this->scratch.'/audits.zip',
        'pso_events' => $this->scratch.'/events.json', 'appointment_history' => $this->scratch.'/history.csv',
    ], []);

    $messages = json_decode(file_get_contents($this->scratch.'/messages.json'), true);
    $args = json_decode(file_get_contents($argsFile), true)['argv'];
    expect($result->result['system_day']['day']['tz'])->toBe('America/Regina')
        ->and($result->summary['system_day'])->toBeTrue()
        ->and(array_keys($messages))->toEqualCanonicalizing(['3041', '1767', '2959', '1964', '1965'])
        ->and($args)->toContain('--messages', $this->scratch.'/messages.json', '--audits', '--events', '--appointments')
        ->and($result->summary['system_day_csv'])->toBeTrue()
        ->and(ToolRunFiles::get($this->run, 'system_day_inputs.csv'))->toStartWith('file_id,');
});

it('leaves system_day null and stores nothing when there are no events and no message texts', function () {
    $argsFile = $this->scratch.'/args.json';
    systemDayEnv('FAKE_AUDIT_ARGS_FILE', $argsFile);

    $result = (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml'], []);

    expect($result->result['system_day'])->toBeNull()
        ->and($result->summary['system_day'])->toBeFalse()
        ->and(ToolRunFiles::get($this->run, 'system_day_inputs.csv'))->toBeNull()
        ->and(file_exists($this->scratch.'/messages.json'))->toBeFalse()
        ->and(json_decode(file_get_contents($argsFile), true)['argv'])->not->toContain('--messages');
});

it('deletes a run\'s stored files when the run is deleted', function () {
    file_put_contents($this->scratch.'/one.csv', 'a,b');
    ToolRunFiles::put($this->run, 'system_day_inputs.csv', $this->scratch.'/one.csv');
    expect(ToolRunFiles::get($this->run, 'system_day_inputs.csv'))->toBe('a,b');

    $this->run->delete();

    expect(Storage::disk('r2')->exists("tool-runs/{$this->run->id}/system_day_inputs.csv"))->toBeFalse();
});

it('deletes the stored files of a user\'s runs when the user is deleted', function () {
    file_put_contents($this->scratch.'/one.csv', 'a,b');
    ToolRunFiles::put($this->run, 'system_day_inputs.csv', $this->scratch.'/one.csv');

    $this->user->delete();

    expect(Storage::disk('r2')->exists("tool-runs/{$this->run->id}/system_day_inputs.csv"))->toBeFalse()
        ->and(ToolRun::withoutGlobalScopes()->whereKey($this->run->id)->exists())->toBeFalse();
});

it('deletes the stored files of a dataset\'s runs when the dataset is deleted', function () {
    $dataset = Dataset::factory()->create();
    $run = ToolRun::factory()->for($dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'result' => null]);
    file_put_contents($this->scratch.'/one.csv', 'a,b');
    ToolRunFiles::put($run, 'system_day_inputs.csv', $this->scratch.'/one.csv');

    $dataset->delete();

    expect(Storage::disk('r2')->exists("tool-runs/{$run->id}/system_day_inputs.csv"))->toBeFalse();
});

it('throws a plain message when the disk refuses the write', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andReturn(false);
    Storage::set('r2', $disk);
    file_put_contents($this->scratch.'/one.csv', 'a,b');

    expect(fn () => ToolRunFiles::put($this->run, 'system_day_inputs.csv', $this->scratch.'/one.csv'))
        ->toThrow(RuntimeException::class, 'The inputs CSV could not be stored.');
});

it('warns instead of failing the audit when the inputs CSV cannot be stored', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andReturn(false);
    Storage::set('r2', $disk);

    $result = (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml', 'pso_events' => $this->scratch.'/events.json'], []);

    expect($result->warnings)->toContain('The inputs CSV could not be stored.')
        ->and($result->summary['system_day_csv'])->toBeFalse()
        ->and($result->result['system_day'])->toBeArray();
});

it('warns when event files were given but the script wrote no System day data', function () {
    systemDayEnv('FAKE_AUDIT_MODE', 'nosystemday');

    $result = (new LoadAuditTool)->run($this->context, ['load' => $this->scratch.'/load.xml', 'pso_events' => $this->scratch.'/events.json'], []);

    expect($result->warnings)->toContain('The System day page could not be made from the event files.')
        ->and($result->result['system_day'])->toBeNull();
});

it('deletes the stored files of an environment\'s runs when the environment is deleted', function () {
    $environment = Environment::factory()->create();
    $run = ToolRun::factory()->for($environment, 'scope')->create(['tool' => ToolId::LoadAudit, 'result' => null]);
    file_put_contents($this->scratch.'/one.csv', 'a,b');
    ToolRunFiles::put($run, 'system_day_inputs.csv', $this->scratch.'/one.csv');

    $environment->delete();

    expect(Storage::disk('r2')->exists("tool-runs/{$run->id}/system_day_inputs.csv"))->toBeFalse();
});

it('deletes the stored files of a dataset run when its environment is deleted', function () {
    $environment = Environment::factory()->create();
    $dataset = Dataset::factory()->for($environment)->create();
    $run = ToolRun::factory()->for($dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'result' => null]);
    file_put_contents($this->scratch.'/one.csv', 'a,b');
    ToolRunFiles::put($run, 'system_day_inputs.csv', $this->scratch.'/one.csv');

    $environment->delete();

    expect(Storage::disk('r2')->exists("tool-runs/{$run->id}/system_day_inputs.csv"))->toBeFalse();
});

it('preselects the events and appointment history files of the LOAD day and labels them', function () {
    app(ToolRegistry::class)->register(ToolId::LoadAudit, LoadAuditTool::class);
    $this->actingAs($this->user);
    $dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $make = static fn (ExportKind $kind, array $summary) => ExportFile::factory()->for($dataset, 'scope')->create(['kind' => $kind, 'summary' => $summary]);
    $load = $make(ExportKind::Load, ['export_date' => '2026-10-10']);
    $events = $make(ExportKind::PsoEvents, ['first_at' => '2026-10-10T12:00:00Z', 'event_count' => 4]);
    $history = $make(ExportKind::AppointmentHistory, ['first_at' => '2026-10-10T12:00:00Z', 'row_count' => 1234]);
    $make(ExportKind::PsoEvents, ['first_at' => '2026-10-08T12:00:00Z']);

    $picker = app(RunInputPicker::class);

    expect($picker->defaultsFor($dataset, ToolId::LoadAudit))->toBe(['load' => $load->id, 'pso_events' => $events->id, 'appointment_history' => $history->id])
        ->and($picker->optionsFor($dataset, ToolId::LoadAudit)['appointment_history'][$history->id])->toContain('1,234 rows');
});
