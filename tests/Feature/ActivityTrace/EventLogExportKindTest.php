<?php

use App\Enums\ExportKind;
use App\Filament\Resources\DatasetResource\Pages\EditDataset;
use App\Filament\Resources\DatasetResource\Pages\ViewDataset;
use App\Filament\Resources\DatasetResource\RelationManagers\ExportFilesRelationManager as DatasetExports;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2', 'exports.max_file_megabytes' => 400]);
    $this->user = User::factory()->create(['email' => 'tester@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create(['name' => 'NORTH']);
    $this->actingAs($this->user);
});

it('is a dataset export of xlsx or csv with a plain label', function () {
    expect(ExportKind::EventLog->value)->toBe('event_log')
        ->and(ExportKind::EventLog->allowedScope())->toBe(Dataset::class)
        ->and(ExportKind::EventLog->extensions())->toBe(['xlsx', 'csv'])
        ->and(ExportKind::EventLog->expectedRoot())->toBeNull()
        ->and(ExportKind::EventLog->getLabel())->toBe('IFS event log')
        ->and(ExportKind::forScope(Dataset::class))->toContain(ExportKind::EventLog)
        ->and(ExportKind::forScope(Environment::class))->not->toContain(ExportKind::EventLog);
});

it('refuses an xml file and accepts an xlsx file for the event log slot', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('temporaryUploadUrl')->andReturn(['url' => 'https://r2.example/put', 'headers' => []]);
    Storage::set('r2', $disk);
    $payload = ['scope_type' => 'dataset', 'scope_id' => $this->dataset->id, 'kind' => 'event_log', 'file_size' => 1024];

    $this->postJson(route('filament.app.exports.slots.store'), $payload + ['file_name' => 'log.xml'])
        ->assertStatus(422)->assertJsonValidationErrors('file_name');
    $this->postJson(route('filament.app.exports.slots.store'), $payload + ['file_name' => 'log.xlsx'])
        ->assertCreated();

    expect(ExportFile::first()->content_type)->toBe('xlsx');
});

it('shows the event count and span in the dataset time zone on the Exports tab', function () {
    $this->dataset->update(['settings' => ['time_zone' => 'America/Regina']]);
    $file = ExportFile::factory()->for($this->dataset, 'scope')->create([
        'kind' => ExportKind::EventLog, 'original_filename' => 'events.xlsx', 'content_type' => 'xlsx',
        'summary' => ['event_count' => 1234, 'first_event' => '2026-10-04T14:30:00+00:00', 'last_event' => '2026-10-05T01:15:00+00:00'],
    ]);

    Livewire::test(DatasetExports::class, ['ownerRecord' => $this->dataset->fresh(), 'pageClass' => ViewDataset::class])
        ->assertCanSeeTableRecords([$file])
        ->assertSee('1,234 events, 4 Oct 08:30 to 4 Oct 19:15');
});

it('saves the IFS event log time zone from the dataset form', function () {
    Livewire::test(EditDataset::class, ['record' => $this->dataset->getRouteKey()])
        ->fillForm(['settings.ifs_time_zone' => 'America/Toronto'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->dataset->fresh()->settings->ifsTimeZone)->toBe('America/Toronto');
});
