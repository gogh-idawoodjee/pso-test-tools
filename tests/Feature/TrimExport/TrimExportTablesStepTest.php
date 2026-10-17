<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Filament\Pages\Tools\TrimExportEntry;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.scratch_path' => sys_get_temp_dir().'/exports-trim-'.uniqid()]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->counts = ['Input_Reference' => 1, 'Activity' => 7620, 'Activity_Status' => 7620, 'Plan' => 1, 'Plan_Travel' => 4812, 'Resources' => 85];
});

/** A page whose single-picker guard is gone, as a forged form state would leave it: every preselected file reaches start(). */
class TrimExportEntryWithoutChosenFiles extends TrimExportEntry
{
    protected function chosenFiles(array $files): array
    {
        return $this->data['files'] ?? [];
    }
}

/** @param array<string, mixed> $attributes */
function trimUnfiledExport(User $user, ExportKind $kind, array $attributes = []): ExportFile
{
    return ExportFile::factory()->for($user, 'scope')->create(['kind' => $kind, ...$attributes]);
}

it('asks which export to trim and shows one picker for that kind', function () {
    trimUnfiledExport($this->user, ExportKind::Load);

    Livewire::test(TrimExportEntry::class)
        ->assertFormSet(['where' => 'unfiled', 'input_kind' => 'load'])
        ->assertSee('Export to trim')
        ->assertFormFieldExists('files.load')
        ->assertFormFieldDoesNotExist('files.plan')
        ->fillForm(['input_kind' => 'plan'])
        ->assertFormFieldExists('files.plan')
        ->assertFormFieldDoesNotExist('files.load');
});

it('opens on the kind of export the user has', function () {
    trimUnfiledExport($this->user, ExportKind::Plan);

    Livewire::test(TrimExportEntry::class)->assertFormSet(['input_kind' => 'plan']);
});

it('sends only the chosen kind even when a LOAD, plan and End of day are all preselected', function () {
    Queue::fake();
    $summary = ['export_date' => '2026-10-04', 'table_counts' => $this->counts];
    $load = trimUnfiledExport($this->user, ExportKind::Load, ['summary' => $summary]);
    $plan = trimUnfiledExport($this->user, ExportKind::Plan, ['summary' => $summary]);
    $eod = trimUnfiledExport($this->user, ExportKind::Eod, ['summary' => $summary]);

    Livewire::test(TrimExportEntry::class)
        ->assertFormSet(['files.load' => $load->id, 'files.plan' => $plan->id, 'files.eod' => $eod->id])
        ->fillForm(['input_kind' => 'plan'])
        ->call('prepareTables')
        ->call('start')
        ->assertHasNoFormErrors();

    expect(ToolRun::sole()->inputs['files'])->toBe(['plan' => $plan->id]);
});

it('lists the tables in the file with their row counts and ticks the Cleanup preset', function () {
    trimUnfiledExport($this->user, ExportKind::Load, ['summary' => ['table_counts' => $this->counts]]);

    Livewire::test(TrimExportEntry::class)
        ->call('prepareTables')
        ->assertFormSet(['options.tables' => ['Plan', 'Plan_Travel'], 'options.preset' => 'cleanup', 'options.table_mode' => 'remove'])
        ->assertSee('Plan output: Plan_Travel, 4,812 rows')
        ->assertSee('Removing 2 tables, 4,813 rows. Keeping 4 tables, 15,326 rows.');
});

it('switches the preset to Custom when a tick changes, and back to the Cleanup list when chosen', function () {
    trimUnfiledExport($this->user, ExportKind::Load, ['summary' => ['table_counts' => $this->counts]]);

    Livewire::test(TrimExportEntry::class)
        ->call('prepareTables')
        ->fillForm(['options.tables' => ['Plan']])
        ->assertFormSet(['options.preset' => 'custom'])
        ->fillForm(['options.preset' => 'cleanup'])
        ->assertFormSet(['options.tables' => ['Plan', 'Plan_Travel']]);
});

it('warns as soon as a kept table loses its parent', function () {
    trimUnfiledExport($this->user, ExportKind::Load, ['summary' => ['table_counts' => $this->counts]]);

    Livewire::test(TrimExportEntry::class)
        ->call('prepareTables')
        ->assertDontSee('is kept but')
        ->fillForm(['options.tables' => ['Activity']])
        ->assertSee('Activity_Status is kept but Activity is removed, so its rows will point at nothing.');
});

it('keeps Input_Reference locked until its removal is allowed', function () {
    trimUnfiledExport($this->user, ExportKind::Load, ['summary' => ['table_counts' => $this->counts]]);

    Livewire::test(TrimExportEntry::class)
        ->call('prepareTables')
        ->assertSee('(always kept unless allowed below)')
        ->fillForm(['options.tables' => ['Input_Reference', 'Plan']])
        ->assertSee('Removing 1 table, 1 row.')
        ->fillForm(['options.allow_input_reference_removal' => true])
        ->assertSee('Removing 2 tables, 2 rows.');
});

it('reads the tables from the file when its summary has none, once, and keeps the scan encrypted', function () {
    $file = trimUnfiledExport($this->user, ExportKind::Load, ['summary' => ['export_date' => '2026-10-04'], 'content_type' => 'xml']);
    $key = app(ExportStorage::class)->objectKey($file);
    Storage::disk('r2')->put($key, file_get_contents(trimXml(trimDataset())));

    Livewire::test(TrimExportEntry::class)->call('prepareTables')->assertSee('Activities: Activity, 4 rows');

    expect(Cache::get("trim-export:tables:{$file->id}"))->toBeString()->not->toContain('Activity');

    Storage::disk('r2')->delete($key);
    Livewire::test(TrimExportEntry::class)->call('prepareTables')->assertSee('Activities: Activity, 4 rows');
});

it('needs at least one tick in Keep mode', function () {
    trimUnfiledExport($this->user, ExportKind::Load, ['summary' => ['table_counts' => $this->counts]]);

    Livewire::test(TrimExportEntry::class)
        ->call('prepareTables')
        ->fillForm(['options.table_mode' => 'keep', 'options.tables' => []])
        ->call('start')
        ->assertHasFormErrors(['options.tables' => 'required']);

    expect(ToolRun::query()->count())->toBe(0);
});

it('does not leave Inputs without a file to read', function () {
    Livewire::test(TrimExportEntry::class)
        ->call('prepareTables')
        ->assertNotified('Choose an export first');
});

it('refuses more than one export as a notification, not an error page', function () {
    Queue::fake();
    $summary = ['table_counts' => $this->counts];
    trimUnfiledExport($this->user, ExportKind::Load, ['summary' => $summary]);
    trimUnfiledExport($this->user, ExportKind::Plan, ['summary' => $summary]);

    Livewire::test(TrimExportEntryWithoutChosenFiles::class)
        ->call('prepareTables')
        ->call('start')
        ->assertSet('runId', null)
        ->assertNotified(Notification::make()->title('The run did not start')->body('Trim export takes one export at a time.')->danger());

    expect(ToolRun::query()->count())->toBe(0);
});

it('refuses a chosen export that is no longer usable as a notification and empties its picker', function () {
    Queue::fake();
    $plan = trimUnfiledExport($this->user, ExportKind::Plan, ['summary' => ['table_counts' => $this->counts]]);

    $component = Livewire::test(TrimExportEntry::class)->assertFormSet(['input_kind' => 'plan', 'files.plan' => $plan->id])->call('prepareTables');
    $plan->update(['status' => ExportFileStatus::Purged]);

    $component->call('start')
        ->assertSet('runId', null)
        ->assertNotified('The run did not start')
        ->assertFormSet(['files.plan' => null]);

    expect(ToolRun::query()->count())->toBe(0);
});
