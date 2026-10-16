<?php

use App\Enums\ExportKind;
use App\Filament\Pages\Tools\PlanTravelsEntry;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH', 'settings' => ['time_zone' => 'America/Regina']]);
    $this->actingAs($this->user);
});

it('fills the first date from the chosen plan export and starts with one day and Google off', function () {
    $plan = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan, 'summary' => ['export_date' => '2026-10-05']]);

    Livewire::test(PlanTravelsEntry::class)
        ->assertFormSet(['files.plan' => $plan->id, 'options.first_date' => '2026-10-05', 'options.days' => 1, 'options.google' => false]);
});

it('follows a different plan export, but never replaces a date the user typed', function () {
    $older = ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan, 'summary' => ['export_date' => '2026-10-04'], 'created_at' => now()->subHour()]);
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan, 'summary' => ['export_date' => '2026-10-05']]);

    $page = Livewire::test(PlanTravelsEntry::class)->assertFormSet(['options.first_date' => '2026-10-05']);
    $page->set('data.files.plan', $older->id)->call('rememberChoice', 'plan', $older->id)
        ->assertFormSet(['options.first_date' => '2026-10-04']);

    $page->set('data.options.first_date', '2026-10-01')->call('rememberChoice', 'plan', $older->id)
        ->assertFormSet(['options.first_date' => '2026-10-01']);
});

it('leaves the date blank when the export does not say its day', function () {
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan, 'summary' => []]);

    Livewire::test(PlanTravelsEntry::class)->assertFormSet(['options.first_date' => null]);
});

it('shows the Google fields only when the toggle is on, with their defaults', function () {
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan, 'summary' => ['export_date' => '2026-10-05']]);

    Livewire::test(PlanTravelsEntry::class)
        ->assertFormFieldHidden('options.travel_mode')
        ->assertFormFieldHidden('options.review_pct')
        ->fillForm(['options.google' => true])
        ->assertFormFieldVisible('options.travel_mode')
        ->assertFormFieldVisible('options.routing_preference')
        ->assertFormFieldVisible('options.review_minutes')
        ->assertFormFieldVisible('options.review_pct')
        ->assertFormSet(['options.travel_mode' => 'DRIVE', 'options.routing_preference' => 'TRAFFIC_UNAWARE', 'options.review_minutes' => 20, 'options.review_pct' => 0])
        ->assertSee('Costs one Google call for each trip that is not already in the shared cache on pso-services.');
});
