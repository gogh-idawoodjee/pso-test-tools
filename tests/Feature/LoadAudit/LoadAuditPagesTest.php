<?php

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Filament\Pages\ToolRunPage;
use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Filament\Resources\DatasetResource\RelationManagers\ToolRunsRelationManager as DatasetRuns;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolRun;
use App\Models\User;
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
    $this->get(route('load-audit.dashboard', [$this->dataset, $this->run]))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'sandbox allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation')
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('Audit page', false);
});

it('gives 404 to another user, to a run of another tool, and to a run without a page yet', function () {
    $other = ToolRun::factory()->for(Dataset::factory(), 'scope')->create(['tool' => ToolId::LoadAudit]);
    $this->get(route('load-audit.dashboard', [$other->scope_id, $other]))->assertNotFound();

    $review = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::ScheduleReview, 'result' => ['html' => 'x']]);
    $this->get(route('load-audit.dashboard', [$this->dataset, $review]))->assertNotFound();

    $running = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'status' => ToolRunStatus::Running, 'result' => null]);
    $this->get(route('load-audit.dashboard', [$this->dataset, $running]))->assertNotFound();
});

it('requires a login', function () {
    auth()->logout();
    $this->get(route('load-audit.dashboard', [$this->dataset, $this->run]))->assertRedirect();
});

it('downloads the page and the findings under a name built from account, dataset and date', function () {
    $this->get(route('load-audit.download-html', [$this->dataset, $this->run]))
        ->assertOk()
        ->assertDownload('load_audit_SATE_NORTH_2026-10-04.html');

    $this->get(route('load-audit.download-findings', [$this->dataset, $this->run]))
        ->assertOk()
        ->assertDownload('load_audit_SATE_NORTH_2026-10-04.json')
        ->assertJson([['check' => 'x']]);
});

it('cleans the download name', function () {
    $this->dataset->update(['name' => 'North / West']);

    $this->get(route('load-audit.download-html', [$this->dataset, $this->run]))->assertDownload('load_audit_SATE_North___West_2026-10-04.html');
});

it('shows counts, download buttons and the sandboxed iframe on the run page', function () {
    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id])
        ->assertOk()
        ->assertSee('2 red')->assertSee('1 amber')->assertSee('9 green')
        ->assertSee('Download HTML')->assertSee('Download findings JSON')
        ->assertSeeHtml('sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"')
        ->assertSeeHtml(e(route('load-audit.dashboard', [$this->dataset, $this->run])));
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

    Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->assertSee('Running the checks')
        ->assertSee('2 red, 1 amber, 9 green');
});
