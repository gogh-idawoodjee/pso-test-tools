<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Filament\Pages\ToolRunPage;
use App\Filament\Pages\Tools\RegionAvailabilityEntry;
use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Filament\Resources\DatasetResource\RelationManagers\ToolRunsRelationManager as DatasetRuns;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user)->state(['account_id' => 'ACME']))->create(['name' => 'NORTH']);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::RegionAvailability, 'run_date' => '2026-10-04',
        'result' => [
            'html' => '<!doctype html><html><body>Regions page <script>1</script></body></html>',
            'data' => ['format' => 1, 'meta' => ['week' => '2026-10-05', 'counts' => ['techs' => 118, 'shifts' => 500, 'techs_with_changes' => 9]], 'techs' => []],
        ],
        'summary' => ['week' => '2026-10-05', 'counts' => ['techs' => 118, 'shifts' => 500, 'techs_with_changes' => 9], 'line' => '118 technicians, 9 with changes, week of 2026-10-05'],
    ]);
    $this->actingAs($this->user);
});

it('serves the stored page to its owner inside a sandbox', function () {
    $this->get(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run]))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'sandbox allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation')
        ->assertSee('Regions page', false);
});

it('downloads the page and the data under the tool, account, dataset and week', function () {
    $this->get(route('tool-runs.download-html', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run]))
        ->assertOk()
        ->assertDownload('region_availability_ACME_NORTH_2026-10-05.html');

    $this->get(route('tool-runs.download-json', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run]))
        ->assertOk()
        ->assertDownload('region_availability_ACME_NORTH_2026-10-05.json')
        ->assertJsonPath('meta.week', '2026-10-05');
});

it('gives 404 to another user and to a run without a page yet', function () {
    $running = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::RegionAvailability, 'status' => ToolRunStatus::Running, 'result' => null]);
    $this->get(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $running]))->assertNotFound();

    $this->actingAs(User::factory()->create(['email' => 'other@mac.com']));
    $this->get(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run]))->assertNotFound();
});

it('shows the summary line on the Runs tab next to the load audit counts', function () {
    ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'summary' => ['counts' => ['red' => 2, 'amber' => 1, 'green' => 9]]]);
    $withoutCounts = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'summary' => ['other' => 1]]);

    Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->assertSee('118 technicians, 9 with changes, week of 2026-10-05')
        ->assertSee('2 red, 1 amber, 9 green')
        ->assertSee('Result')
        ->assertTableColumnStateSet('result_summary', null, $withoutCounts);
});

it('shows the counts, the week, the download buttons and the sandboxed iframe on the run page', function () {
    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id])
        ->assertOk()
        ->assertSee('118 technicians')->assertSee('500 shifts')->assertSee('9 with changes')
        ->assertSee('Week of 2026-10-05')
        ->assertSee('Download HTML')->assertSee('Download JSON')
        ->assertSeeHtml('sandbox="allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation"')
        ->assertSeeHtml(e(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run])));
});

it('renders a run without a page without an iframe or download buttons', function () {
    $noPage = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::RegionAvailability, 'result' => ['data' => []], 'summary' => []]);

    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $noPage->id])
        ->assertOk()
        ->assertDontSeeHtml('<iframe')
        ->assertDontSee('Download HTML')
        ->assertSee('page is not available');
});

it('offers Week of on the Run form and validates it', function (array $options, ?string $invalidField) {
    Queue::fake();
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['export_date' => '2026-10-10']]);

    $component = Livewire::test(DatasetRuns::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->callTableAction('run_region_availability', data: ['files' => ['load' => $load->id], 'options' => $options]);

    if ($invalidField === null) {
        $component->assertHasNoTableActionErrors();
        expect(ToolRun::query()->where('tool', ToolId::RegionAvailability)->latest('id')->first()->inputs['options'])->toBe($options);
    } else {
        $component->assertHasTableActionErrors([$invalidField]);
    }
})->with([
    'blank week' => [['week_of' => null], null],
    'a date' => [['week_of' => '2026-10-07'], null],
    'not a date' => [['week_of' => 'not a date'], 'options.week_of'],
]);

it('listens for the page week message and runs the same LOAD for that week as a new run', function () {
    Queue::fake();
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);
    $this->run->update(['inputs' => ['files' => ['load' => $load->id], 'options' => ['week_of' => '2026-10-05']]]);

    $component = Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id])
        ->assertSeeHtml('pso-tools.region-availability.week')
        ->call('runWeek', '2026-10-12');

    $next = ToolRun::query()->where('tool', ToolId::RegionAvailability)->whereKeyNot($this->run->id)->sole();
    expect($next->inputs)->toBe(['files' => ['load' => $load->id], 'options' => ['week_of' => '2026-10-12']]);
    $component->assertRedirect(ToolRunPage::urlFor($this->dataset, $next));
});

it('ignores a week message that is not a date and refuses one whose LOAD is gone', function () {
    Queue::fake();
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);
    $this->run->update(['inputs' => ['files' => ['load' => $load->id], 'options' => []]]);

    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id])
        ->call('runWeek', '2026-13-45')
        ->call('runWeek', 'next')
        ->assertNoRedirect();
    expect(ToolRun::count())->toBe(1);

    $load->delete();
    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id])
        ->call('runWeek', '2026-10-12')
        ->assertNoRedirect()
        ->assertNotified();
    expect(ToolRun::count())->toBe(1);
});

it('runs the week in place on the tool wizard page', function () {
    Queue::fake();
    $load = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Load]);

    $component = Livewire::test(RegionAvailabilityEntry::class)
        ->fillForm(['options' => ['week_of' => '2026-10-05']])
        ->call('start')->assertHasNoFormErrors();
    $first = ToolRun::query()->where('tool', ToolId::RegionAvailability)->whereKeyNot($this->run->id)->sole();
    expect($component->get('runId'))->toBe($first->id);

    $component->call('runWeek', '2026-09-28');

    $next = ToolRun::query()->where('tool', ToolId::RegionAvailability)->whereKeyNot([$this->run->id, $first->id])->sole();
    expect($next->inputs['options'])->toBe(['week_of' => '2026-09-28'])
        ->and($component->get('runId'))->toBe($next->id)
        ->and($component->get('data.options.week_of'))->toBe('2026-09-28');
});
