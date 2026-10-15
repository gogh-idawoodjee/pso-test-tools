<?php

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Filament\Resources\DatasetResource\RelationManagers\ExportFilesRelationManager as DatasetExports;
use App\Filament\Resources\EnvironmentResource\Pages\ViewEnvironment;
use App\Filament\Resources\EnvironmentResource\RelationManagers\ExportFilesRelationManager as EnvironmentExports;
use App\Livewire\ExportDropZone;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2']);
    $this->user = User::factory()->create();
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('lists a dataset export with its summary and purge countdown', function () {
    $this->travelTo(now()->startOfMinute());
    $file = ExportFile::factory()->for($this->dataset, 'scope')->create([
        'kind' => ExportKind::Load, 'original_filename' => 'morning-load.zip',
        'summary' => ['export_date' => '2026-10-10', 'activity_count' => 1234, 'resource_count' => 56],
        'expires_at' => now()->addHours(9),
    ]);

    Livewire::test(DatasetExports::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->assertCanSeeTableRecords([$file])
        ->assertSee('morning-load.zip')
        ->assertSee('1,234')
        ->assertSee('Purges in 9 h 0 m');
});

it('offers only sys on an environment and the other four on a dataset', function () {
    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset])
        ->assertSee('LOAD')->assertSee('End of day')->assertDontSee('SYS');
    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset->environment])
        ->assertSee('SYS')->assertDontSee('LOAD');
});

it('deletes the object with the row', function () {
    $file = ExportFile::factory()->for($this->dataset, 'scope')->create(['content_type' => 'xml']);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($file), 'x');

    Livewire::test(DatasetExports::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->callTableAction('delete', $file);

    expect(ExportFile::withoutGlobalScopes()->find($file->id))->toBeNull()
        ->and(app(ExportStorage::class)->exists($file))->toBeFalse();
});

it('shows a failed upload with its reason on the row', function () {
    $file = ExportFile::factory()->for($this->dataset->environment, 'scope')->sys()->failed()->create(['error_message' => 'This file is a <dsScheduleData> export, not a SYS.']);

    Livewire::test(EnvironmentExports::class, ['ownerRecord' => $this->dataset->environment, 'pageClass' => ViewEnvironment::class])
        ->assertSee('Failed')
        ->assertSee('not a SYS');
});

it('shows allocations for a plan export', function () {
    $file = ExportFile::factory()->for($this->dataset, 'scope')->create([
        'kind' => ExportKind::Plan, 'original_filename' => 'plan.zip',
        'summary' => ['export_date' => '2026-10-04', 'allocation_count' => 7803],
    ]);

    Livewire::test(DatasetExports::class, ['ownerRecord' => $this->dataset, 'pageClass' => ViewDataset::class])
        ->assertCanSeeTableRecords([$file])
        ->assertSee('7,803 allocations');
});

it('shows the file being read on the target of its kind, among all the targets', function () {
    ExportFile::factory()->for($this->dataset, 'scope')->create(['kind' => ExportKind::Plan, 'original_filename' => 'north_plan.zip', 'status' => ExportFileStatus::Uploading]);

    Livewire::test(ExportDropZone::class, ['scope' => $this->dataset])
        ->assertSee('Reading north_plan.zip')
        ->assertDontSee('Drop a new Plan')
        ->assertSee('Drop a new LOAD')
        ->assertSet('serverStates', ['plan' => 'reading']);
});
