<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Filament\Pages\ToolRunPage;
use App\Filament\Pages\Tools\LoadAuditEntry;
use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Filament\Resources\DatasetResource\RelationManagers\ToolRunsRelationManager as DatasetRuns;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Settings\AppSettings;
use App\Tools\LoadAudit\LoadAuditCommand;
use App\Tools\LoadAudit\LoadAuditTool;
use App\Tools\LoadAudit\PsoToolsCheckout;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user)->state(['account_id' => 'SATE']))->create(['name' => 'NORTH']);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'run_date' => '2026-10-04',
        'result' => ['html' => '<!doctype html><html><body>Audit page <script>1</script></body></html>', 'findings' => [['check' => 'x']], 'counts' => ['red' => 2, 'amber' => 1, 'green' => 9]],
        'summary' => ['counts' => ['red' => 2, 'amber' => 1, 'green' => 9]],
    ]);
    $this->actingAs($this->user);
});

it('serves the stored dashboard to its owner inside a sandbox', function () {
    $this->get(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run]))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'sandbox allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation')
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('Audit page', false);
});

it('gives 404 to another user and to a run without a page yet, and serves another tool that has a page', function () {
    $other = ToolRun::factory()->for(Dataset::factory(), 'scope')->create(['tool' => ToolId::LoadAudit]);
    $this->get(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $other->scope_id, 'run' => $other]))->assertNotFound();

    $review = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::ScheduleReview, 'result' => ['html' => '<p>Review page</p>']]);
    $this->get(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $review]))->assertOk()->assertSee('Review page', false);

    $running = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Running, 'result' => null]);
    $this->get(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $running]))->assertNotFound();
});

it('requires a login', function () {
    auth()->logout();
    $this->get(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run]))->assertRedirect();
});

it('downloads the page and the findings under a name built from account, dataset and date', function () {
    $this->get(route('tool-runs.download-html', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run]))
        ->assertOk()
        ->assertDownload('load_audit_SATE_NORTH_2026-10-04.html');

    $this->get(route('tool-runs.download-json', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run]))
        ->assertOk()
        ->assertDownload('load_audit_SATE_NORTH_2026-10-04.json')
        ->assertJson([['check' => 'x']]);
});

it('cleans the download name', function () {
    $this->dataset->update(['name' => 'North / West']);

    $this->get(route('tool-runs.download-html', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run]))->assertDownload('load_audit_SATE_North___West_2026-10-04.html');
});

it('shows counts, download buttons and the sandboxed iframe on the run page', function () {
    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id])
        ->assertOk()
        ->assertSee('2 red')->assertSee('1 amber')->assertSee('9 green')
        ->assertSee('Download HTML')->assertSee('Download findings JSON')
        ->assertSeeHtml('sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"')
        ->assertSeeHtml(e(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run])));
});

it('shows the progress label and bar while a run is going, polling every two seconds', function () {
    $running = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Running, 'result' => null,
        'progress' => ['label' => 'Reading the LOAD: 45%, about 6 s left', 'percent' => 45, 'seconds_left' => 6],
    ]);

    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $running->id])
        ->assertOk()
        ->assertSee('Reading the LOAD: 45%, about 6 s left')
        ->assertSeeHtml('wire:poll.2s')
        ->assertSeeHtml('width: 45%');
});

it('shows the progress label and the counts on the Runs tab', function () {
    ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Running, 'result' => null,
        'progress' => ['label' => 'Running the checks', 'percent' => null, 'seconds_left' => null],
    ]);

    $component = Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->assertSee('Running the checks')
        ->assertSee('2 red, 1 amber, 9 green');

    expect(substr_count($component->html(), '2 red, 1 amber, 9 green'))->toBe(1);
});

it('shows the placeholder on the Runs tab when a summary has no counts or other keys', function () {
    ToolRun::query()->delete();
    ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::ScheduleReview, 'summary' => ['rows' => 4]]);
    ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::ScheduleReview, 'summary' => ['counts' => ['red' => 3]]]);
    ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::ScheduleReview, 'summary' => null]);

    $component = Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->assertOk();

    expect(substr_count($component->html(), '3 red, 0 amber, 0 green'))->toBe(1);
});

it('gives 404 for a run that belongs to another dataset of the same user', function () {
    $datasetB = Dataset::factory()->for($this->dataset->environment)->create(['name' => 'SOUTH']);
    $runB = ToolRun::factory()->for($datasetB, 'scope')->create(['tool' => ToolId::LoadAudit, 'result' => ['html' => '<p>b</p>', 'findings' => []]]);

    foreach (['tool-runs.page', 'tool-runs.download-html', 'tool-runs.download-json'] as $name) {
        $this->get(route($name, ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $runB]))->assertNotFound();
    }
});

it('gives 403 to a user who cannot access the panel', function () {
    $this->actingAs(User::factory()->create(['email' => 'someone@example.com']));

    $this->get(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run]))->assertForbidden();
});

it('renders a run without a page without an iframe or download buttons', function () {
    $noPage = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'result' => ['findings' => []], 'summary' => null]);

    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $noPage->id])
        ->assertOk()
        ->assertDontSeeHtml('<iframe')
        ->assertDontSee('Download HTML')
        ->assertSee('audit page is not available');
});

it('validates the real load audit options on the Run form', function (array $options, ?string $invalidField) {
    Queue::fake();
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['export_date' => '2026-10-10']]);

    $component = Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->callTableAction('run_load_audit', data: ['files' => ['load' => $load->id], 'options' => $options]);

    if ($invalidField === null) {
        $component->assertHasNoTableActionErrors();
    } else {
        $component->assertHasTableActionErrors([$invalidField]);
    }
})->with([
    'release abc' => [['ifs_release' => 'abc', 'complete_grace_hours' => 24], 'options.ifs_release'],
    'release 25.2.7' => [['ifs_release' => '25.2.7', 'complete_grace_hours' => 24], null],
    'release blank' => [['ifs_release' => '', 'complete_grace_hours' => 24], null],
    'grace 169' => [['ifs_release' => '', 'complete_grace_hours' => 169], 'options.complete_grace_hours'],
    'grace -1' => [['ifs_release' => '', 'complete_grace_hours' => -1], 'options.complete_grace_hours'],
    'grace 0' => [['ifs_release' => '', 'complete_grace_hours' => 0], null],
    'grace 24' => [['ifs_release' => '', 'complete_grace_hours' => 24], null],
]);

it('keeps a cleared noise ids field blank through the real wizard, so the run gets no --noise-ids', function () {
    Queue::fake();
    app(ToolRegistry::class)->register(ToolId::LoadAudit, LoadAuditTool::class);
    app(AppSettings::class)->put(AppSettings::NOISE_EVENT_IDS, '3041,24578');
    $this->user->rememberToolContext($this->dataset->environment_id, $this->dataset->id);
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    Livewire::test(LoadAuditEntry::class)
        ->fillForm(['where' => 'dataset'])
        ->assertFormSet(['options.noise_event_ids' => '3041,24578'])
        ->fillForm(['options' => ['noise_event_ids' => '']])
        ->call('start')
        ->assertHasNoFormErrors();

    $run = ToolRun::query()->latest('id')->first();
    expect($run->inputs['options'])->toHaveKey('noise_event_ids')
        ->and($run->inputs['options']['noise_event_ids'])->toBe('');

    $argv = LoadAuditCommand::build(
        new PsoToolsCheckout(base_path('tests/Fixtures/pso-tools'), 'python3'), '/s',
        ['load' => '/s/l.xml', 'pso_events' => '/s/e.json'], $run->inputs['options'], 'UTC', null,
    );
    expect($argv)->not->toContain('--noise-ids');
});
