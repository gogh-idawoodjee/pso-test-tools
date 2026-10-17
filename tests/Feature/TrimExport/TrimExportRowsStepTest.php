<?php

use App\Enums\ExportKind;
use App\Filament\Pages\Tools\TrimExportEntry;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-trim-'.uniqid()]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->file = ExportFile::factory()->for($this->user, 'scope')->create([
        'kind' => ExportKind::Load, 'content_type' => 'xml', 'summary' => ['table_counts' => ['Input_Reference' => 1, 'Activity' => 4]],
    ]);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($this->file), file_get_contents(trimXml(trimDataset())));
});

function trimRowsReady(): Testable
{
    return Livewire::test(TrimExportEntry::class)->call('prepareTables')->call('prepareRows');
}

it('fills the filters from the chosen file once the Tables step is done', function () {
    $component = Livewire::test(TrimExportEntry::class)->call('prepareTables');

    expect($component->instance()->filterChoices())->toBeNull();

    $choices = $component->call('prepareRows')->instance()->filterChoices();

    expect($choices->regions)->toBe(['V1' => 'V1 - Regina', 'V2' => 'V2 - Saskatoon'])
        ->and($choices->resources)->toBe(['R1' => 'R1 - Ada Lovelace', 'R2' => 'R2 - Alan Turing'])
        ->and($choices->activityTypes)->toBe(['T1' => 'T1 - Install (3)', 'T2' => 'T2 - Repair (1)'])
        ->and($choices->activities)->toBe(['A1' => 'A1 - T1', 'A2' => 'A2 - T1', 'A3' => 'A3 - T2', 'A4' => 'A4 - T1'])
        ->and($choices->resourceRegions)->toBe(['R1' => ['V1'], 'R2' => ['V2']]);
});

it('caches the choices per export file, encrypted', function () {
    trimRowsReady();

    expect(Cache::get("trim-export:choices:{$this->file->id}"))->toBeString()->not->toContain('Lovelace');

    Storage::disk('r2')->delete(app(ExportStorage::class)->objectKey($this->file));

    expect(trimRowsReady()->instance()->filterChoices()?->regions)->toHaveKey('V1');
});

it('narrows resources to the chosen regions and drops a chosen resource outside them', function () {
    trimRowsReady()
        ->fillForm(['options.resource_ids' => ['R2']])
        ->fillForm(['options.region_ids' => ['V1']])
        ->assertFormSet(['options.resource_ids' => []]);
});

it('needs both window days, the end not before the start', function () {
    trimRowsReady()
        ->fillForm(['options.window_start' => '2026-10-09'])
        ->call('start')
        ->assertHasFormErrors(['options.window_end' => 'required']);

    trimRowsReady()
        ->fillForm(['options.window_start' => '2026-10-09', 'options.window_end' => '2026-10-05'])
        ->call('start')
        ->assertHasFormErrors(['options.window_end']);

    expect(ToolRun::query()->count())->toBe(0);
});

it('starts a run with the row options and the time zone of an Unfiled run', function () {
    Queue::fake();

    trimRowsReady()
        ->fillForm([
            'options.region_ids' => ['V1'],
            'options.window_start' => '2026-10-05',
            'options.window_end' => '2026-10-09',
            'options.count_only' => true,
            'options.time_zone' => 'America/Regina',
        ])
        ->call('start')
        ->assertHasNoFormErrors();

    expect(ToolRun::sole()->inputs['options'])->toMatchArray([
        'table_mode' => 'remove',
        'region_ids' => ['V1'],
        'window_start' => '2026-10-05',
        'window_end' => '2026-10-09',
        'count_only' => true,
        'time_zone' => 'America/Regina',
    ]);
});

it('starts a run with the chosen resources, activity types and activities', function () {
    Queue::fake();

    trimRowsReady()
        ->fillForm(['options.resource_ids' => ['R1'], 'options.activity_type_ids' => ['T1']])
        ->fillForm(['options.activity_ids' => ['A1', 'A4']])
        ->call('start')
        ->assertHasNoFormErrors();

    expect(ToolRun::sole()->inputs['options'])->toMatchArray([
        'resource_ids' => ['R1'],
        'activity_type_ids' => ['T1'],
        'activity_ids' => ['A1', 'A4'],
        'count_only' => false,
    ]);
});

it('carries the remembered zone into an Unfiled run and keeps it remembered', function () {
    Queue::fake();
    $this->user->rememberToolContext(null, null, unfiled: true, timeZone: 'America/Toronto');

    trimRowsReady()
        ->assertFormFieldVisible('options.time_zone')
        ->assertFormSet(['options.time_zone' => 'America/Toronto'])
        ->call('start')
        ->assertHasNoFormErrors();

    expect(ToolRun::sole()->inputs['options']['time_zone'])->toBe('America/Toronto')
        ->and($this->user->fresh()->last_tool_context['time_zone'])->toBe('America/Toronto');
});

it('reaches the run with the Input_Reference datetime override', function () {
    Queue::fake();

    trimRowsReady()
        ->fillForm(['options.input_reference_datetime' => '2026-10-05 06:00:00'])
        ->call('start')
        ->assertHasNoFormErrors();

    expect(ToolRun::sole()->inputs['options']['input_reference_datetime'])->toStartWith('2026-10-05 06:00');
});

it('clears the row choices when another file is chosen', function () {
    // Older, so the picker still preselects the file the beforeEach stored.
    $other = ExportFile::factory()->for($this->user, 'scope')->create(['kind' => ExportKind::Load, 'summary' => ['table_counts' => ['Activity' => 1]], 'created_at' => now()->subHour()]);

    $component = trimRowsReady()
        ->fillForm(['options.region_ids' => ['V1']])
        ->fillForm(['files.load' => $other->id])
        ->assertFormSet(['options.region_ids' => []]);

    expect($component->instance()->filterChoices())->toBeNull();
});
