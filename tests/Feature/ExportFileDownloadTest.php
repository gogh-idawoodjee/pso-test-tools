<?php

use App\Enums\ExportFileStatus;
use App\Filament\Widgets\MyExportFiles;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\User;
use App\Support\Exports\ExportStorage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('r2');
    config(['exports.disk' => 'r2']);
    $this->user = User::factory()->create(['email' => 'ish@mac.com']);
    $this->dataset = Dataset::factory()->for(Environment::factory()->for($this->user))->create();
    $this->file = ExportFile::factory()->for($this->dataset, 'scope')->create(['original_filename' => 'north-load.trimmed.xml', 'content_type' => 'xml']);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($this->file), '<dsScheduleData/>');
    $this->actingAs($this->user);
});

it('streams an export to its owner under its own name', function () {
    $this->get(route('exports.download', ['exportFile' => $this->file->id]))
        ->assertOk()
        ->assertDownload('north-load.trimmed.xml')
        ->assertStreamedContent('<dsScheduleData/>');
});

it('never serves a SYS export, which holds live credentials', function () {
    $sys = ExportFile::factory()->for($this->dataset->environment, 'scope')->sys()->create();
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($sys), '<DsSystemData/>');

    $this->get(route('exports.download', ['exportFile' => $sys->id]))->assertNotFound();
});

it('refuses another user, a purged file and an expired one', function () {
    $url = route('exports.download', ['exportFile' => $this->file->id]);

    $this->actingAs(User::factory()->create(['email' => 'other@mac.com']))->get($url)->assertNotFound();

    $this->actingAs($this->user);
    $this->file->update(['status' => ExportFileStatus::Purged]);
    $this->get($url)->assertNotFound();

    $this->file->update(['status' => ExportFileStatus::Ready, 'expires_at' => now()->subMinute()]);
    $this->get($url)->assertNotFound();
});

it('sends a guest to the login page', function () {
    Auth::logout();

    $this->get(route('exports.download', ['exportFile' => $this->file->id]))->assertRedirect();
});

it('offers Download on the Exports table for a file that is still stored', function () {
    $mine = ExportFile::factory()->for($this->user, 'scope')->create(['original_filename' => 'load.xml']);
    $purged = ExportFile::factory()->for($this->user, 'scope')->purged()->create();

    Livewire::test(MyExportFiles::class)
        ->assertTableActionHasUrl('download', route('exports.download', ['exportFile' => $mine->id]), record: $mine)
        ->assertTableActionHidden('download', $purged);
});

it('serves an Unfiled export to its owner only, and never an environment-scoped one (SYS)', function () {
    $unfiled = ExportFile::factory()->for($this->user, 'scope')->create(['original_filename' => 'mine.xml', 'content_type' => 'xml']);
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($unfiled), '<x/>');
    $onEnvironment = ExportFile::factory()->for($this->dataset->environment, 'scope')->sys()->create();
    Storage::disk('r2')->put(app(ExportStorage::class)->objectKey($onEnvironment), '<x/>');

    $this->get(route('exports.download', ['exportFile' => $unfiled->id]))->assertOk();
    $this->get(route('exports.download', ['exportFile' => $onEnvironment->id]))->assertNotFound();

    $this->actingAs(User::factory()->create(['email' => 'other@mac.com']));
    $this->get(route('exports.download', ['exportFile' => $unfiled->id]))->assertNotFound();
    $this->get(route('exports.download', ['exportFile' => $onEnvironment->id]))->assertNotFound();
});

it('checks ownership explicitly, whatever the query scope finds', function () {
    $other = User::factory()->create(['email' => 'other@mac.com']);

    expect($this->file->isOwnedBy($this->user))->toBeTrue()
        ->and($this->file->isOwnedBy($other))->toBeFalse()
        ->and(ExportFile::factory()->for($this->dataset->environment, 'scope')->sys()->create()->isOwnedBy($this->user))->toBeTrue()
        ->and(ExportFile::factory()->for($this->dataset->environment, 'scope')->sys()->create()->isOwnedBy($other))->toBeFalse();
});
