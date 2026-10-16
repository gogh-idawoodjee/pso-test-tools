<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\ToolRunPage;
use App\Filament\Pages\Tools\AppointmentTimelineEntry;
use App\Filament\Widgets\ToolCatalogue;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Tools\ActivityTrace\ActivityTraceTool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

function fakeTraceEnv(string $name, ?string $value): void
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
    config(['tools.pso_tools_path' => base_path('tests/Fixtures/pso-tools'), 'tools.python' => 'python3', 'tools.activity_trace.timeout_seconds' => 840]);
    fakeTraceEnv('FAKE_TRACE_MODE', 'ok');
    $this->scratch = sys_get_temp_dir().'/at-'.uniqid();
    mkdir($this->scratch);
    file_put_contents($this->scratch.'/audits.zip', 'zip');
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user)->state(['account_id' => 'SATE']))->create([
        'name' => 'NORTH', 'settings' => ['time_zone' => 'America/Regina', 'ifs_time_zone' => 'America/Toronto'],
    ]);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::AppointmentTimeline, 'result' => null, 'progress' => null]);
    $this->context = new ToolContext($this->run, $this->dataset, $this->dataset->settings, 'America/Regina');
    $this->actingAs($this->user);
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
    fakeTraceEnv('FAKE_TRACE_MODE', null);
    fakeTraceEnv('FAKE_TRACE_ARGS_FILE', null);
    fakeTraceEnv('FAKE_TRACE_RESOLVED_ACTIVITY', null);
});

it('is registered and asks for audits, with load and event log optional', function () {
    $tool = app(ToolRegistry::class)->for(ToolId::AppointmentTimeline);

    expect($tool)->toBeInstanceOf(ActivityTraceTool::class)
        ->and($tool->requiredKinds())->toBe([ExportKind::Audits])
        ->and($tool->optionalKinds())->toBe([ExportKind::Load, ExportKind::EventLog])
        ->and(ToolId::AppointmentTimeline->getLabel())->toBe('Trace an activity')
        ->and(AppointmentTimelineEntry::canAccess())->toBeTrue();
});

it('offers the activity id and work task id options', function () {
    $names = array_map(static fn ($field) => $field->getName(), (new ActivityTraceTool)->optionFields());

    expect($names)->toBe(['options.activity_id', 'options.work_task_id']);
});

it('runs the script and stores the page, data, both CSVs, counts, summary and warnings', function () {
    $argsFile = $this->scratch.'/args.json';
    fakeTraceEnv('FAKE_TRACE_ARGS_FILE', $argsFile);

    $result = (new ActivityTraceTool)->run($this->context, ['audits' => $this->scratch.'/audits.zip'], ['activity_id' => 'abc']);

    $args = json_decode(file_get_contents($argsFile), true);
    expect($args['argv'])->toContain('--activity', 'abc', '--tz', 'America/Regina', '--ifs-tz', 'America/Toronto', '--dataset-name=NORTH')
        ->and($args['progress_env'])->toBe('lines')
        ->and($result->result['html'])->toContain('Trace page')
        ->and($result->result['data']['findings'])->toHaveCount(4)
        ->and($result->result['booking_csv'])->toStartWith('time,event')
        ->and($result->result['history_csv'])->toStartWith('time,step')
        ->and($result->summary)->toBe(['line' => 'Activity abc: Booked 2026-10-03 after 2 offers; 14 scheduling events, 1 check failed', 'counts' => ['red' => 1, 'amber' => 2, 'green' => 1], 'activity_id' => 'abc', 'work_task_id' => null])
        ->and($result->warnings)->toBe(['the LOAD has no slot usage rules']);
});

it('names the activity the script found for a work task, or the work task when it found none', function () {
    fakeTraceEnv('FAKE_TRACE_RESOLVED_ACTIVITY', '813775');
    $found = (new ActivityTraceTool)->run($this->context, ['audits' => $this->scratch.'/audits.zip'], ['work_task_id' => 'WT9']);
    fakeTraceEnv('FAKE_TRACE_RESOLVED_ACTIVITY', null);
    $notFound = (new ActivityTraceTool)->run($this->context, ['audits' => $this->scratch.'/audits.zip'], ['work_task_id' => 'WT9']);

    expect($found->summary['line'])->toStartWith('Activity 813775: Booked')
        ->and($found->summary['activity_id'])->toBe('813775')
        ->and($notFound->summary['line'])->toStartWith('Work task WT9: Booked')
        ->and($notFound->summary['activity_id'])->toBeNull();
});

it('fails with the scripts own words when the activity is not found', function () {
    fakeTraceEnv('FAKE_TRACE_MODE', 'notfound');

    expect(fn () => (new ActivityTraceTool)->run($this->context, ['audits' => $this->scratch.'/audits.zip'], ['activity_id' => '123']))
        ->toThrow(RuntimeException::class, 'Activity 123 is not in the audit messages.');
});

it('names the scripts own stage and drops the exception class name when it fails', function (string $mode, string $message) {
    fakeTraceEnv('FAKE_TRACE_MODE', $mode);

    try {
        (new ActivityTraceTool)->run($this->context, ['audits' => $this->scratch.'/audits.zip'], ['activity_id' => '123']);
        $this->fail('expected the run to fail');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe($message);
    }
})->with([
    'two-line form' => ['fail', "The trace stopped while reading the audit files: 'Activity_Status'"],
    'one-line form' => ['eventlogfail', 'The trace stopped while reading the IFS event log: The event log has no Time column.'],
]);

it('reports the finding and reading progress', function () {
    $labels = [];
    $this->run::updating(static function ($run) use (&$labels): void {
        $labels[] = $run->progress['label'] ?? null;
    });

    (new ActivityTraceTool)->run($this->context, ['audits' => $this->scratch.'/audits.zip'], ['activity_id' => 'abc']);

    expect($labels)->toContain('Finding the activity in the audit messages: 0%')
        ->and($this->run->fresh()->progress['label'] ?? null)->toBe('Reading the audit messages: 40%');
});

it('says there is no error text when the not-found exit has an empty stderr', function () {
    fakeTraceEnv('FAKE_TRACE_MODE', 'blank');

    expect(fn () => (new ActivityTraceTool)->run($this->context, ['audits' => $this->scratch.'/audits.zip'], ['activity_id' => '123']))
        ->toThrow(RuntimeException::class, 'no error text');
});

it('fails when the script exits cleanly but wrote nothing', function () {
    fakeTraceEnv('FAKE_TRACE_MODE', 'silent');

    expect(fn () => (new ActivityTraceTool)->run($this->context, ['audits' => $this->scratch.'/audits.zip'], ['activity_id' => '123']))
        ->toThrow(RuntimeException::class, 'The trace finished but wrote no results.');
});

function storedTrace(): ToolRun
{
    return ToolRun::factory()->for(test()->dataset, 'scope')->create([
        'tool' => ToolId::AppointmentTimeline, 'run_date' => '2026-10-04',
        'inputs' => ['files' => [], 'options' => ['activity_id' => '813775']],
        'result' => ['html' => '<html>Trace page</html>', 'data' => ['findings' => []], 'booking_csv' => "time,event\n1,offer\n", 'history_csv' => "time,step\n1,plan\n"],
        'summary' => ['line' => 'Activity 813775: Booked after 2 offers', 'counts' => ['red' => 1, 'amber' => 2, 'green' => 3], 'activity_id' => '813775', 'work_task_id' => '438745'],
    ]);
}

it('shows badges, the summary line, four downloads and the sandboxed page on the run page', function () {
    $run = storedTrace();

    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $run->id])
        ->assertOk()
        ->assertSee('Trace an activity: activity 813775')
        ->assertSee('Activity 813775')
        ->assertSee('Work task 438745')
        ->assertSee('1 red')->assertSee('2 amber')->assertSee('3 green')
        ->assertSee('Booked after 2 offers')
        ->assertSee('Download HTML')->assertSee('Download JSON')->assertSee('Download booking CSV')->assertSee('Download history CSV')
        ->assertSeeHtml('sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"')
        ->assertSeeHtml(e(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $run])));
});

it('serves the stored CSVs as downloads', function () {
    $run = storedTrace();

    $this->get(route('tool-runs.download-booking-csv', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $run]))
        ->assertOk()->assertDownload('appointment_timeline_SATE_NORTH_2026-10-04_activity813775_booking.csv')
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $this->get(route('tool-runs.download-history-csv', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $run]))
        ->assertOk()->assertDownload('appointment_timeline_SATE_NORTH_2026-10-04_activity813775_history.csv');
    $this->get(route('tool-runs.download-html', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $run]))
        ->assertOk()->assertDownload('appointment_timeline_SATE_NORTH_2026-10-04_activity813775.html');
});

it('gives 404 on the CSV routes for another user or a missing file', function () {
    $other = ToolRun::factory()->for(Dataset::factory(), 'scope')->create(['tool' => ToolId::AppointmentTimeline, 'result' => ['html' => '<p>x</p>', 'booking_csv' => 'a', 'history_csv' => 'b']]);
    $this->get(route('tool-runs.download-booking-csv', ['scopeType' => 'datasets', 'scopeId' => $other->scope_id, 'run' => $other]))->assertNotFound();

    $none = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::AppointmentTimeline, 'result' => ['html' => '<p>x</p>']]);
    $this->get(route('tool-runs.download-history-csv', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $none]))->assertNotFound();
});

it('shows three input rows and the two option fields in the wizard', function () {
    $page = Livewire::test(AppointmentTimelineEntry::class)->assertOk();
    $html = $page->html();

    expect(substr_count($html, 'Choose a file') + substr_count($html, 'export uploaded yet'))->toBe(3);
    $page->assertSee('Audit messages export')->assertSee('LOAD export')->assertSee('IFS event log export')
        ->assertDontSee('Plan export')
        ->assertSee('Activity id')->assertSee('Work task id')
        ->assertSee('The PSO activity id. Give this or the work task id.');
});

it('lists the tool in the catalogue with a correct article', function () {
    Livewire::test(ToolCatalogue::class)
        ->assertSee('Trace an activity')
        ->assertSee('Needs an Audit messages export; LOAD, IFS event log optional');
});

it('passes no --tz when the run uses the default zone, but still an IFS zone that was set', function () {
    $argsFile = $this->scratch.'/args.json';
    fakeTraceEnv('FAKE_TRACE_ARGS_FILE', $argsFile);
    $this->dataset->update(['settings' => ['ifs_time_zone' => 'America/Toronto']]);
    $context = new ToolContext($this->run, $this->dataset->fresh(), $this->dataset->fresh()->settings, 'UTC', timeZoneIsDefault: true);

    (new ActivityTraceTool)->run($context, ['audits' => $this->scratch.'/audits.zip'], ['activity_id' => 'abc']);

    $argv = json_decode(file_get_contents($argsFile), true)['argv'];
    expect($argv)->not->toContain('--tz')->toContain('--ifs-tz', 'America/Toronto');
});

it('names an unfiled trace after the audits file and passes the chosen zone, with no IFS zone', function () {
    $argsFile = $this->scratch.'/args.json';
    fakeTraceEnv('FAKE_TRACE_ARGS_FILE', $argsFile);
    $audits = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Audits, 'original_filename' => 'audits_1004.zip', 'content_type' => 'zip']);
    $run = ToolRun::factory()->for($this->user, 'scope')->create([
        'tool' => ToolId::AppointmentTimeline, 'result' => null, 'progress' => null,
        'inputs' => ['files' => ['audits' => $audits->id], 'options' => ['activity_id' => 'abc', 'time_zone' => 'America/Regina']],
    ]);

    (new ActivityTraceTool)->run(new ToolContext($run, $this->user, null, 'America/Regina'), ['audits' => $this->scratch.'/audits.zip'], ['activity_id' => 'abc']);

    $argv = json_decode(file_get_contents($argsFile), true)['argv'];
    expect($argv)->toContain('--dataset-name=audits_1004.zip')
        ->and($argv[array_search('--tz', $argv, true) + 1])->toBe('America/Regina')
        ->and($argv)->not->toContain('--ifs-tz');
});

it('keeps the tool label as the page title when a run names nothing', function () {
    $run = storedTrace();
    $run->update(['summary' => ['line' => 'No appointment request', 'counts' => []]]);

    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $run->id])
        ->assertOk()
        ->assertSee('Trace an activity')
        ->assertDontSee('Trace an activity:');
    expect(ToolRunPage::subjectOf($run->fresh()))->toBeNull();

    $run->update(['summary' => ['line' => 'x', 'counts' => [], 'work_task_id' => '446613']]);
    expect(ToolRunPage::subjectOf($run->fresh()))->toBe('Work task 446613');
});
