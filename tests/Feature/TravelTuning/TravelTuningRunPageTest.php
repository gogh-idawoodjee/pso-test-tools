<?php

use App\Enums\Tool as ToolId;
use App\Filament\Pages\ToolRunPage;
use App\Filament\Widgets\RecentRuns;
use App\Http\Controllers\ToolRunPageController;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Tools\TravelTuning\TravelTuningTool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->environment = Environment::factory()->for($this->user)->create(['account_id' => 'SATE']);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH', 'settings' => ['time_zone' => 'America/Regina']]);
    $this->base = planTravelsRun($this->dataset, ['created_at' => Carbon::parse('2026-10-13 04:35:00', config('app.timezone'))]);
    $this->final = planTravelsRun($this->dataset);
    $this->sys = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['original_filename' => 'PSO_Sys_pass_1.xml']);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::TravelTuning, 'started_by_user_id' => $this->user->id, 'run_date' => null,
        ...ToolRun::inputAttributes(app(TravelTuningTool::class), ['files' => [], 'options' => [], 'runs' => [
            ['run_id' => $this->base->id, 'label' => 'Prod (no polygons)', 'sys_file_id' => null],
            ['run_id' => $this->final->id, 'label' => 'Grid v6', 'sys_file_id' => $this->sys->id],
        ]]),
        'result' => ['html' => '<!doctype html><title>Travel Tuning Report</title><p>tuning page</p>'],
        'summary' => ['runs' => 2, 'baseline_flagged' => 75, 'final_flagged' => 10, 'baseline_error_hours' => 138.2, 'final_error_hours' => 98.2,
            'fixed' => 44, 'newly_broken' => 0, 'shared_legs' => 2112, 'first_date' => '2026-10-05',
            'line' => '2 runs: flagged 75 to 10, total error 138.2 to 98.2 hours, 44 fixed, 0 newly broken, 2,112 shared legs'],
        'warnings' => ['Legs common to every run are 55% of the smallest run.'],
    ]);
    $this->actingAs($this->user);
    $this->params = ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id];
    $this->routeParams = ToolRunPageController::params($this->dataset, $this->run);
});

it('shows the headline, the downloads, the page and the source runs with their polygons', function () {
    Livewire::test(ToolRunPage::class, $this->params)
        ->assertOk()
        ->assertSee('2 runs: flagged 75 to 10, total error 138.2 to 98.2 hours, 44 fixed, 0 newly broken, 2,112 shared legs')
        ->assertSee('Flagged 75 to 10')
        ->assertSee('Download HTML')
        ->assertSeeHtml(e(route('tool-runs.download-html', $this->routeParams)))
        ->assertSeeHtml('title="Travel tuning"')
        ->assertSee('Prod (no polygons)')
        ->assertSee('Grid v6')
        ->assertSeeHtml(e(ToolRunPage::urlFor($this->dataset, $this->base)))
        ->assertSee('Plan travels run of 13 Oct 2026 02:35')
        ->assertSee('PSO_Sys_pass_1.xml')
        ->assertSee('Polygons: None')
        ->assertSee('Legs common to every run are 55% of the smallest run.')
        ->assertDontSee('No inputs recorded.')
        ->assertDontSee('Download JSON');
});

it('shows the labels on the run page only, never in the run lists or the plain inputs', function () {
    Livewire::test(RecentRuns::class)->assertCanSeeTableRecords([$this->run])->assertDontSee('Grid v6');
    expect(DB::table('tool_runs')->where('id', $this->run->id)->value('inputs'))->not->toContain('Grid v6')->not->toContain('Prod (no polygons)');
});

it('names a source run that has been deleted, and keeps the page', function () {
    $this->base->delete();

    Livewire::test(ToolRunPage::class, $this->params)
        ->assertOk()
        ->assertSee('Plan travels run deleted')
        ->assertSeeHtml('title="Travel tuning"');
});

it('serves the page sandboxed and the download named after the account, dataset and baseline date', function () {
    $this->get(route('tool-runs.page', $this->routeParams))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', ToolRunPageController::SANDBOX)
        ->assertSee('tuning page', false);

    $this->get(route('tool-runs.download-html', $this->routeParams))
        ->assertOk()
        ->assertDownload('travel_tuning_SATE_NORTH_2026-10-05.html');
});

it('keeps another user\'s tuning run out of reach', function () {
    $this->actingAs(User::factory()->create(['email' => 'someone@mac.com']));

    Livewire::test(ToolRunPage::class, $this->params)->assertNotFound();
    $this->get(route('tool-runs.download-html', $this->routeParams))->assertNotFound();
});

it('shows an Unfiled tuning run at unfiled/me', function () {
    $mine = planTravelsRun($this->user);
    $run = ToolRun::factory()->for($this->user, 'scope')->create([
        'tool' => ToolId::TravelTuning, 'started_by_user_id' => $this->user->id,
        ...ToolRun::inputAttributes(app(TravelTuningTool::class), ['files' => [], 'options' => [], 'runs' => [['run_id' => $mine->id, 'label' => 'Baseline', 'sys_file_id' => null], ['run_id' => $mine->id, 'label' => 'Run 1', 'sys_file_id' => null]]]),
        'result' => ['html' => '<p>tuning page</p>'], 'summary' => ['runs' => 2, 'line' => '2 runs'],
    ]);

    Livewire::test(ToolRunPage::class, ['scope' => 'unfiled', 'scopeId' => 'me', 'run' => $run->id])
        ->assertOk()
        ->assertSeeHtml(e(ToolRunPage::urlFor($this->user, $mine)));
});
