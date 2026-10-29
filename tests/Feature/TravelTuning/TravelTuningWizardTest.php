<?php

use App\Enums\ExportFileStatus;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\Tools\TravelTuningEntry;
use App\Filament\Widgets\ToolCatalogue;
use App\Jobs\RunToolJob;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-tuning-'.uniqid()]);
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->environment = Environment::factory()->for($this->user)->create(['account_id' => 'SATE']);
    $this->dataset = Dataset::factory()->for($this->environment)->create(['name' => 'NORTH', 'settings' => ['time_zone' => 'America/Regina', 'display_name' => 'SaskTel', 'dataset_display_name' => 'North', 'region_label' => 'Territory']]);
    $this->user->rememberToolContext($this->environment->id, $this->dataset->id);
    $this->actingAs($this->user);
    $this->base = planTravelsRun($this->dataset, ['created_at' => Carbon::parse('2026-10-13 04:35:00', 'UTC')->setTimezone(config('app.timezone'))]);
    $this->final = planTravelsRun($this->dataset, ['created_at' => Carbon::parse('2026-10-14 04:35:00', 'UTC')->setTimezone(config('app.timezone'))]);
    $this->sys = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['original_filename' => 'PSO_Sys_pass_1.xml', 'summary' => ['polygon_count' => 251]]);
});

function tuningRuns(array $rows): array
{
    $keyed = [];
    foreach ($rows as $index => $row) {
        $keyed["row{$index}"] = ['run_id' => null, 'label' => null, 'sys_file_id' => null, ...$row];
    }

    return $keyed;
}

it('is in the catalogue and the Analyse group', function () {
    expect(TravelTuningEntry::canAccess())->toBeTrue();
    Livewire::test(ToolCatalogue::class)->assertSee('Travel tuning')->assertSee('Needs two or more Plan travels runs checked with Google');
});

it('lists the dataset\'s finished plan travels runs and says which need Google', function () {
    planTravelsRun($this->dataset, ['summary' => ['leg_count' => 9, 'review_count' => 1, 'first_date' => '2026-09-01', 'last_date' => '2026-09-02', 'google' => null]]);
    planTravelsRun(Dataset::factory()->for($this->environment)->create(), ['summary' => ['leg_count' => 1, 'review_count' => 0, 'first_date' => '2026-08-01', 'last_date' => '2026-08-01', 'google' => []]]);

    Livewire::test(TravelTuningEntry::class)->fillForm(['where' => 'dataset'])
        ->assertSee('5 Oct 2026')
        ->assertSee('12 Oct 2026 22:35')
        ->assertSee('1 Sep 2026 to 2 Sep 2026')
        ->assertSee('Run plan travels again with Google on')
        ->assertSee('A finished report keeps working after its plan travels runs and SYS exports are gone.')
        ->assertDontSee('1 Aug 2026');
});

it('prefills the options from the dataset settings and the script defaults', function () {
    Livewire::test(TravelTuningEntry::class)->fillForm(['where' => 'dataset'])->assertFormSet([
        'options.title' => 'Travel Tuning Report', 'options.client' => 'SaskTel', 'options.dataset' => 'North', 'options.region_label' => 'Territory',
        'options.tiles' => 'esri-gray', 'options.review_minutes' => 20, 'options.small_minutes' => 5, 'options.long_leg_km' => 300,
        'options.model_tol_min' => 1, 'options.model_tol_pct' => 5,
    ]);
});

it('prefills the region label with Region when the dataset sets none and for Unfiled', function () {
    $this->dataset->update(['settings' => ['time_zone' => 'America/Regina', 'display_name' => 'SaskTel']]);

    Livewire::test(TravelTuningEntry::class)->assertFormSet(['options.region_label' => 'Region']);
    Livewire::test(TravelTuningEntry::class)->fillForm(['where' => 'unfiled'])->assertFormSet(['options.region_label' => 'Region']);
});

it('starts a run with the runs in order, the default labels and the chosen SYS', function () {
    Queue::fake();

    Livewire::test(TravelTuningEntry::class)->fillForm(['where' => 'dataset'])
        ->set('data.runs', tuningRuns([['run_id' => $this->base->id], ['run_id' => $this->final->id, 'label' => 'Grid v6', 'sys_file_id' => $this->sys->id]]))
        ->call('start')
        ->assertHasNoFormErrors();

    $run = ToolRun::query()->where('tool', ToolId::TravelTuning)->sole();
    $raw = (string) DB::table('tool_runs')->where('id', $run->id)->value('inputs');
    // toEqual: the merged rows carry the label last (it comes back from the encrypted column).
    expect($run->sourceRuns())->toEqual([
        ['run_id' => $this->base->id, 'label' => 'Baseline', 'sys_file_id' => null],
        ['run_id' => $this->final->id, 'label' => 'Grid v6', 'sys_file_id' => $this->sys->id],
    ])->and($run->options()['client'])->toBe('SaskTel')
        ->and($run->options())->not->toHaveKey('time_zone')
        ->and($raw)->not->toContain('Grid v6')->not->toContain('Baseline')->not->toContain('SaskTel')->not->toContain('Travel Tuning Report');
    Queue::assertPushed(RunToolJob::class, 1);
});

it('clears a SYS export with no Polygon table as soon as it is picked', function () {
    $empty = ExportFile::factory()->for($this->environment, 'scope')->sys()->create(['summary' => ['polygon_count' => 0]]);

    Livewire::test(TravelTuningEntry::class)->fillForm(['where' => 'dataset'])
        ->set('data.runs', tuningRuns([['run_id' => $this->base->id], ['run_id' => $this->final->id]]))
        ->set('data.runs.row1.sys_file_id', $empty->id)
        ->assertNotified('This SYS export has no Polygon table')
        ->assertSet('data.runs.row1.sys_file_id', null);
});

it('refuses the same run twice and sends the user back to Inputs', function () {
    Queue::fake();

    Livewire::test(TravelTuningEntry::class)->fillForm(['where' => 'dataset'])
        ->set('data.runs', tuningRuns([['run_id' => $this->base->id], ['run_id' => $this->base->id]]))
        ->call('start')
        ->assertSet('runId', null)
        ->assertNotified('The run did not start')
        ->assertDispatched('go-to-wizard-step', key: 'form.tool-wizard', step: 'form.inputs::data::wizard-step');
    expect(ToolRun::query()->where('tool', ToolId::TravelTuning)->count())->toBe(0);
});

it('asks for at least two runs', function () {
    Livewire::test(TravelTuningEntry::class)->fillForm(['where' => 'dataset'])
        ->set('data.runs', tuningRuns([['run_id' => $this->base->id]]))
        ->call('start')
        ->assertHasFormErrors(['runs']);
});

it('lists the user\'s Unfiled runs on Unfiled, and starts one there', function () {
    Queue::fake();
    $mine = planTravelsRun($this->user, ['summary' => ['leg_count' => 7, 'review_count' => 3, 'first_date' => '2026-07-01', 'last_date' => '2026-07-01', 'google' => []]]);
    $mineToo = planTravelsRun($this->user, ['summary' => ['leg_count' => 7, 'review_count' => 1, 'first_date' => '2026-07-02', 'last_date' => '2026-07-02', 'google' => []]]);

    Livewire::test(TravelTuningEntry::class)
        ->fillForm(['where' => 'unfiled'])
        ->assertSee('1 Jul 2026')
        ->assertDontSee('5 Oct 2026')
        ->assertFormSet(['options.client' => '', 'options.dataset' => ''])
        ->set('data.runs', tuningRuns([['run_id' => $mine->id], ['run_id' => $mineToo->id]]))
        ->call('start')
        ->assertHasNoFormErrors();

    $run = ToolRun::query()->where('tool', ToolId::TravelTuning)->sole();
    expect((string) $run->scope_id)->toBe((string) $this->user->getKey())
        ->and($run->sourceRuns()[1]['label'])->toBe('Run 1');
});

it('keeps checking while a SYS export is being read, and lists it in Polygons once it is', function () {
    $reading = ExportFile::factory()->for($this->environment, 'scope')->sys()->create([
        'original_filename' => 'PSO_Sys_pass_2.xml', 'status' => ExportFileStatus::Summarising, 'summary' => null,
    ]);

    $page = Livewire::test(TravelTuningEntry::class)->fillForm(['where' => 'dataset'])
        ->assertSeeHtml('wire:poll.2s="$refresh"');

    $reading->update(['status' => ExportFileStatus::Ready, 'summary' => ['polygon_count' => 12]]);

    $page->call('$refresh')
        // The drop zone is idle again, so the name can only come from the Polygons options.
        ->assertSee('PSO_Sys_pass_2.xml')
        ->assertDontSeeHtml('wire:poll.2s="$refresh"');
});
