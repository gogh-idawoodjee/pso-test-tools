<?php

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\ToolRunPage;
use App\Filament\Pages\Tools\LoadAuditEntry;
use App\Http\Controllers\ToolRunPageController;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeTool;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user)->state(['account_id' => 'SATE']))->create(['name' => 'NORTH']);
    $this->run = ToolRun::factory()->for($this->user, 'scope')->create([
        'tool' => ToolId::LoadAudit, 'run_date' => '2026-10-04',
        'result' => ['html' => '<!doctype html><html><body>Audit page</body></html>', 'findings' => [['check' => 'x']], 'counts' => ['red' => 2, 'amber' => 1, 'green' => 9]],
        'summary' => ['counts' => ['red' => 2, 'amber' => 1, 'green' => 9]],
    ]);
    $this->actingAs($this->user);
});

it('builds unfiled route parameters with no user id', function () {
    expect(ToolRunPageController::params($this->user, $this->run))->toBe(['scopeType' => 'unfiled', 'scopeId' => 'me', 'run' => $this->run->id])
        ->and(ToolRunPageController::params($this->dataset, $this->run))->toBe(['scopeType' => 'datasets', 'scopeId' => $this->dataset->id, 'run' => $this->run->id])
        ->and(route('tool-runs.page', ToolRunPageController::params($this->user, $this->run)))->toEndWith("/unfiled/me/runs/{$this->run->id}/page")
        ->and(ToolRunPage::urlFor($this->user, $this->run))->toEndWith("/unfiled/me/runs/{$this->run->id}");
});

it('opens an unfiled run page with its sandboxed page and downloads', function () {
    Livewire::test(ToolRunPage::class, ['scope' => 'unfiled', 'scopeId' => 'me', 'run' => $this->run->id])
        ->assertOk()
        ->assertSee('2 red')
        ->assertSeeHtml(e(route('tool-runs.page', ToolRunPageController::params($this->user, $this->run))))
        ->assertSeeHtml(e(route('tool-runs.download-json', ToolRunPageController::params($this->user, $this->run))));
});

it('does not open another user unfiled run, or an unfiled run under any id but me', function () {
    $theirs = ToolRun::factory()->for(User::factory()->create(), 'scope')->create();

    Livewire::test(ToolRunPage::class, ['scope' => 'unfiled', 'scopeId' => 'me', 'run' => $theirs->id])->assertNotFound();
    Livewire::test(ToolRunPage::class, ['scope' => 'unfiled', 'scopeId' => (string) $this->user->id, 'run' => $this->run->id])->assertNotFound();
});

it('serves the page and downloads of an unfiled run and names the files unfiled', function () {
    $params = ToolRunPageController::params($this->user, $this->run);

    $this->get(route('tool-runs.page', $params))
        ->assertOk()
        ->assertSee('Audit page', false)
        ->assertHeader('Content-Security-Policy', ToolRunPageController::SANDBOX);
    $this->get(route('tool-runs.download-html', $params))->assertOk()->assertDownload('load_audit_unfiled_2026-10-04.html');
    $this->get(route('tool-runs.download-json', $params))->assertOk()->assertDownload('load_audit_unfiled_2026-10-04.json');
});

it('names the csv, booking and history downloads of an unfiled run unfiled', function () {
    $this->run->update(['result' => $this->run->result + ['csv' => "a\n", 'booking_csv' => "b\n", 'history_csv' => "h\n"]]);
    $params = ToolRunPageController::params($this->user, $this->run);

    $this->get(route('tool-runs.download-csv', $params))->assertOk()->assertDownload('load_audit_unfiled_2026-10-04.csv');
    $this->get(route('tool-runs.download-booking-csv', $params))->assertOk()->assertDownload('load_audit_unfiled_2026-10-04_booking.csv');
    $this->get(route('tool-runs.download-history-csv', $params))->assertOk()->assertDownload('load_audit_unfiled_2026-10-04_history.csv');
});

it('gives 404 on every unfiled route for another user run, a dataset run, or an id other than me', function () {
    // Every result key a route serves, so a 404 can only come from the scope check.
    $result = ['html' => '<p>x</p>', 'findings' => [], 'csv' => "a\n", 'booking_csv' => "b\n", 'history_csv' => "h\n"];
    $theirs = ToolRun::factory()->for(User::factory()->create(), 'scope')->create(['result' => $result]);
    $datasetRun = ToolRun::factory()->for($this->dataset, 'scope')->create(['result' => $result]);
    $this->run->update(['result' => $result]);

    foreach (['tool-runs.page', 'tool-runs.download-html', 'tool-runs.download-json', 'tool-runs.download-csv', 'tool-runs.download-booking-csv', 'tool-runs.download-history-csv'] as $name) {
        $this->get(route($name, ['scopeType' => 'unfiled', 'scopeId' => 'me', 'run' => $theirs->id]))->assertNotFound();
        $this->get(route($name, ['scopeType' => 'unfiled', 'scopeId' => 'me', 'run' => $datasetRun->id]))->assertNotFound();
        $this->get(route($name, ['scopeType' => 'unfiled', 'scopeId' => (string) $this->user->id, 'run' => $this->run->id]))->assertNotFound();
        $this->get(route($name, ToolRunPageController::params($this->user, $this->run)))->assertOk();
    }
    $this->get("/environments/x/runs/{$this->run->id}/page")->assertNotFound();
});

it('links the wizard run panel of an unfiled run to unfiled/me', function () {
    Queue::fake();
    app(ToolRegistry::class)->register(ToolId::LoadAudit, FakeTool::class);
    ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load]);

    $component = Livewire::test(LoadAuditEntry::class)->fillForm(['where' => 'unfiled'])->call('start')->assertHasNoFormErrors();
    $run = ToolRun::query()->whereKeyNot($this->run->id)->sole();

    $component->assertSee(ToolRunPage::urlFor($this->user, $run), false);
    expect(ToolRunPage::urlFor($this->user, $run))->toContain('/unfiled/me/runs/');
});
