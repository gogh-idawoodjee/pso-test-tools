<?php

use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Filament\Pages\ToolRunPage;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ToolRun;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user)->state(['account_id' => 'SATE']))->create(['name' => 'NORTH']);
    $this->run = ToolRun::factory()->for($this->dataset, 'scope')->create([
        'tool' => ToolId::PlanTravels, 'run_date' => '2026-10-06',
        // The page carries the legs JSON the way plan_travels.py writes it: one compact line, '<' escaped.
        'result' => ['html' => "<!doctype html><html><body>Plan travels page<script>\nconst DATA = {\"meta\":{\"warnings\":[]},\"legs\":[{\"resource_id\":\"R1\",\"flag\":\"Review\",\"note\":\"a \\u003c b\"}]};\n</script></body></html>", 'csv' => "Resource ID,Flag\nR1,Review\n"],
        'summary' => ['leg_count' => 1781, 'resource_count' => 412, 'review_count' => 37, 'missing_count' => 4, 'first_date' => '2026-10-05', 'last_date' => '2026-10-09', 'days' => 5, 'google' => null, 'line' => '1,781 legs for 412 resources, 2026-10-05 to 2026-10-09'],
    ]);
    $this->actingAs($this->user);
});

it('shows the summary line, the legs, Review and Missing badges, both downloads and the page', function () {
    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id])
        ->assertOk()
        ->assertSee('1,781 legs for 412 resources, 2026-10-05 to 2026-10-09')
        ->assertSee('1,781 legs')->assertSee('37 to review')->assertSee('4 missing a travel time')
        ->assertSee('Download CSV')->assertSee('Download JSON')
        ->assertSeeHtml(e(route('tool-runs.download-csv', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run])))
        ->assertSeeHtml('sandbox="allow-scripts allow-downloads allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation"')
        ->assertSeeHtml(e(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run])));
});

it('shows the kept legs and the reason on a run that failed at the Google step', function () {
    $this->run->update(['status' => ToolRunStatus::Failed, 'error_message' => 'The Google Routes check did not finish: Google Routes is not configured on pso-services.', 'warnings' => ['Google Routes was not checked, so the legs have no Google columns: Google Routes is not configured on pso-services.']]);

    Livewire::test(ToolRunPage::class, ['scope' => 'datasets', 'scopeId' => $this->dataset->getRouteKey(), 'run' => $this->run->id])
        ->assertSee('This run did not finish')
        ->assertSee('The Google Routes check did not finish: Google Routes is not configured on pso-services.')
        ->assertSee('Download CSV')
        ->assertSeeHtml(e(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run])));
});

it('serves the CSV named after the account, dataset and first date', function () {
    $this->get(route('tool-runs.download-csv', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run]))
        ->assertOk()
        ->assertDownload('plan_travels_SATE_NORTH_2026-10-05.csv')
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertStreamedContent("Resource ID,Flag\nR1,Review\n");
    $this->get(route('tool-runs.download-json', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run]))
        ->assertOk()->assertDownload('plan_travels_SATE_NORTH_2026-10-05.json')
        ->assertJsonPath('legs.0.resource_id', 'R1')
        ->assertJsonPath('legs.0.note', 'a < b');
});

it('keeps the CSV behind the panel login and the owner', function () {
    $other = ToolRun::factory()->for(Dataset::factory(), 'scope')->create(['tool' => ToolId::PlanTravels, 'result' => ['html' => '<p>x</p>', 'csv' => 'a']]);
    $this->get(route('tool-runs.download-csv', ['scopeType' => 'datasets', 'scopeId' => $other->scope_id, 'run' => $other]))->assertNotFound();

    $noCsv = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::PlanTravels, 'result' => ['html' => '<p>x</p>']]);
    $this->get(route('tool-runs.download-csv', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $noCsv]))->assertNotFound();

    auth()->logout();
    $this->get(route('tool-runs.download-csv', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run]))->assertRedirect();
});

it('serves the plan travels page with links that can leave the sandbox', function () {
    $this->get(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run]))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'sandbox allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation allow-popups-to-escape-sandbox');
});

it('keeps the other tools on the unchanged sandbox', function () {
    $audit = ToolRun::factory()->for($this->dataset, 'scope')->create(['tool' => ToolId::LoadAudit, 'result' => ['html' => '<p>audit</p>']]);

    $this->get(route('tool-runs.page', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $audit]))
        ->assertHeader('Content-Security-Policy', 'sandbox allow-scripts allow-downloads allow-popups allow-top-navigation-by-user-activation');
});

it('downloads an empty JSON document when the page carries no data block', function () {
    $this->run->update(['result' => ['html' => '<!doctype html><html><body>No data here</body></html>', 'csv' => '']]);

    $this->get(route('tool-runs.download-json', ['scopeType' => 'datasets', 'scopeId' => $this->dataset, 'run' => $this->run]))
        ->assertOk()
        ->assertExactJson([]);
});
